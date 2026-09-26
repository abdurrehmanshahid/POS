<?php

namespace Tests\Feature;

use App\Http\Controllers\ReceiptController;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\ChallanActions;
use App\Support\DocumentResponse;
use App\Support\Format;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** End-to-end render + auth + scoping smoke tests for the screens and controllers. */
class ScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 10:00:00');
        config(['institute.today' => null]);
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('username', 'adminansar')->firstOrFail();
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('adminansar');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_dashboard_shows_reconciled_revenue(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('TOTAL BILLED')
            ->assertSee('Rs 214,000')
            ->assertSee('Rs 119,000')
            ->assertSee('Active students');
    }

    public function test_officer_dashboard_hides_money_and_flips_labels(): void
    {
        $this->actingAs($this->officer())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('TOTAL BILLED')
            ->assertDontSee('Rs 214,000')
            ->assertSee('My active students');
    }

    public function test_officer_cannot_reach_admin_only_screens(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer)->get('/courses')->assertForbidden();
        $this->actingAs($officer)->get('/staff')->assertForbidden();
        $this->actingAs($officer)->get('/settings')->assertForbidden();
        $this->actingAs($officer)->get('/reports')->assertForbidden();
        $this->actingAs($officer)->get('/datamodel')->assertForbidden();
    }

    public function test_officer_can_reach_permitted_screens(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer)->get('/registrations')->assertOk();
        $this->actingAs($officer)->get('/challans')->assertOk();
        $this->actingAs($officer)->get('/students')->assertOk();
    }

    // ---- Receipts (GAP-05) --------------------------------------------------

    /**
     * The rule the whole receipt exists for: a reprint must not contradict the
     * copy the student is already holding.
     *
     * A student pays Rs 10,000 of Rs 25,000 and is handed a receipt saying
     * Rs 15,000 remains. They pay the rest next week. Reprinting the FIRST
     * receipt must still say Rs 15,000 — computing the balance from today's
     * ledger would reprint it as Rs 0, and two documents describing one payment
     * would disagree about what happened.
     */
    public function test_a_reprinted_receipt_still_shows_the_balance_as_at_that_payment(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // unpaid, net 25000
        $actions = app(ChallanActions::class);

        $first = $actions->recordPayment($challan, $this->admin(), 10000, 'Cash');
        $first = $challan->fresh()->payments()->orderBy('id')->first();

        $balanceAfterFirst = $this->receiptBalance($first);
        $this->assertSame(15000, $balanceAfterFirst);

        // The rest is collected later.
        $actions->recordPayment($challan->fresh(), $this->admin(), 15000, 'Cash');

        $this->assertSame(15000, $this->receiptBalance($first->fresh()),
            'Reprinting the first receipt must reproduce the original facts, not recompute them.');
    }

    /** The balance a receipt would print for a given payment. */
    private function receiptBalance(Payment $payment): int
    {
        $method = new \ReflectionMethod(ReceiptController::class, 'balanceAfter');

        return $method->invoke(app(ReceiptController::class), $payment->load('challan.payments'));
    }

    /**
     * The receipt number is derived from the payment id, not allocated, so
     * printing twice cannot produce two different numbers for one payment.
     */
    public function test_a_receipt_number_is_stable_across_reprints(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();
        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 5000, 'Cash');

        $payment = $challan->fresh()->payments()->orderBy('id')->first();

        $this->assertSame($payment->receiptNo(), $payment->fresh()->receiptNo());
        $this->assertStringContainsString('RC-', $payment->receiptNo());
    }

    /** Printing a receipt must never write anything; it reports the ledger. */
    public function test_printing_a_receipt_changes_nothing(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();
        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');
        $payment = $challan->fresh()->payments()->orderBy('id')->first();

        $before = [Payment::count(), Challan::count(), $challan->fresh()->balance()];

        $this->actingAs($this->admin())->get(route('payments.receipt', $payment))->assertOk();

        $this->assertSame($before, [Payment::count(), Challan::count(), $challan->fresh()->balance()]);
    }

    /**
     * The voucher prints three copies — bank, student, institute — matching the
     * form every counter in Pakistan already hands over. Rendered rather than
     * asserted on the blade, because a DomPDF template that throws only does so
     * at render time.
     */
    public function test_the_challan_pdf_renders_three_copies_with_advance_and_balance(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // unpaid, net 25000
        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');

        $html = view('challans.pdf', [
            'challan' => $challan->fresh()->load('admission.student', 'admission.course', 'admission.cohort', 'admission.enroller', 'payments'),
            'settings' => Setting::current(),
        ])->render();

        // The standard Pakistani fee challan, in the order the copies are torn
        // off at the bank counter. The bank's is not optional: it is the copy
        // the cashier retains, so a voucher without one cannot be deposited.
        foreach (['Bank Copy', 'Student Copy', 'Institute Copy'] as $copy) {
            $this->assertStringContainsString($copy, $html);
        }

        // And the line the cashier stamps, which is the only mark on this
        // document the institute did not print itself.
        $this->assertStringContainsString('Bank Stamp', $html);

        $this->assertStringContainsString('Advance Payment', $html);
        $this->assertStringContainsString('Balance', $html);
        $this->assertStringContainsString('PART PAID', $html, 'A partly collected challan is neither paid nor unpaid.');
        $this->assertStringContainsString(Format::money(10000), $html);
        $this->assertStringContainsString(Format::money(15000), $html);
        $this->assertStringContainsString('BBT-CH-2026-1076', $html);

        // And it survives an actual PDF render.
        $this->actingAs($this->admin())
            ->get(route('challans.pdf', $challan))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_students_export_streams_csv_with_bom(): void
    {
        $res = $this->actingAs($this->admin())->get(route('students.export'));
        $res->assertOk();
        $this->assertStringContainsString('BBT Students.csv', $res->headers->get('content-disposition'));
        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Student ID,Name,Type', $body);
        $this->assertStringContainsString('BBT-R26-0009', $body);
    }

    public function test_admin_can_render_every_screen(): void
    {
        $admin = $this->admin();
        foreach (['dashboard', 'registrations', 'challans', 'courses', 'students', 'staff', 'reports', 'datamodel', 'settings'] as $r) {
            $this->actingAs($admin)->get('/'.$r)->assertOk();
        }
    }

    public function test_registration_wizard_creates_enrolment_with_next_serials(): void
    {
        $officer = $this->officer();
        $wd = Course::where('code', 'WD-101')->firstOrFail();

        Livewire::actingAs($officer)->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'new')
            ->set('newName', 'Wizard Test')
            ->set('newGuardian', 'Guardian X')
            ->set('newPhone', '+92 300 1112223')
            ->set('newCnic', '35201-0000000-9')
            ->call('next')                 // step 1 -> 2
            ->assertSet('step', 2)
            ->call('toggleCourse', $wd->id)
            ->call('next')                 // step 2 -> 3
            ->assertSet('step', 3)
            ->call('submit');

        $this->assertDatabaseHas('students', ['name' => 'Wizard Test', 'student_code' => 'BBT-R26-0011']);
        $this->assertDatabaseHas('admissions', ['reg_no' => 'BBT-ADM-0012', 'enrolled_by' => $officer->id]);
        $this->assertDatabaseHas('challans', ['challan_no' => 'BBT-CH-2026-1086', 'base_amount' => 20000]);
    }

    public function test_wizard_blocks_invalid_new_student(): void
    {
        Livewire::actingAs($this->officer())->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'new')
            ->set('newName', '')
            ->set('newPhone', '12345')      // invalid PK mobile
            ->set('newCnic', 'bad')
            ->call('next')
            ->assertSet('step', 1);         // stays on step 1
    }

    public function test_challans_mark_paid_flow(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1077')->firstOrFail(); // unpaid

        Livewire::actingAs($this->admin())->test('pages.challans')
            ->call('askPay', $challan->id)
            ->set('payMethod', 'Cash')
            ->call('confirmPay');

        $this->assertSame('paid', $challan->fresh()->status);
        $this->assertSame('Cash', $challan->fresh()->paid_via);
    }

    public function test_courses_save_creates_course(): void
    {
        Livewire::actingAs($this->admin())->test('pages.courses')
            ->call('addCourse')
            ->set('code', 'TEST-900')
            ->set('title', 'Test Course')
            ->set('fee', 15000)
            ->call('save');

        $this->assertDatabaseHas('courses', ['code' => 'TEST-900', 'fee' => 15000]);
    }

    // ---- Downloading and viewing --------------------------------------------

    /**
     * Every download names its file in both forms the web has for saying so.
     *
     * The shortest legal disposition — `filename=x.pdf`, unquoted — is valid and
     * is what Laravel emits by default, but it has to be re-parsed correctly by
     * the browser, any download manager behind it and any proxy between, and
     * where one of them declines the file arrives as an unnamed blob. A voucher
     * saved as a bare UUID is one nobody can find again or attach to an email.
     *
     * Two filenames here carry SPACES, which an unquoted disposition cannot
     * express at all, so this is not hypothetical for the exports.
     *
     * The displayed routes are here too, not in a test of their own. The name
     * matters MORE on those, which is the wrong way round from what you would
     * guess: `inline` is what the PDF.js viewer fetched, and its own Save
     * button reads the filename straight out of this header. Leave it off and
     * viewing-then-saving produces a file called `stream`.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function downloads(): array
    {
        return [
            'challan PDF' => ['challan', 'attachment', 'challan-BBT-CH-2026-1076.pdf'],
            'challan shown' => ['challan-stream', 'inline', 'challan-BBT-CH-2026-1076.pdf'],
            'receipt shown' => ['receipt-stream', 'inline', 'receipt-'],
            'students CSV' => ['students', 'attachment', 'BBT Students.csv'],
            'report XLSX' => ['report', 'attachment', 'BBT Report'],
        ];
    }

    #[DataProvider('downloads')]
    public function test_a_download_states_its_filename_unambiguously(string $kind, string $type, string $expected): void
    {
        $challan = fn () => Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();

        $url = match ($kind) {
            'challan' => route('challans.pdf', $challan()),
            'challan-stream' => route('challans.stream', $challan()),
            'receipt-stream' => route('payments.receipt.stream', $this->aReceipt()),
            'students' => route('students.export'),
            'report' => route('reports.export'),
        };

        $disposition = $this->actingAs($this->admin())->get($url)
            ->assertOk()
            ->headers->get('Content-Disposition');

        $this->assertStringStartsWith($type.';', $disposition);

        // Quoted, for everything that reads RFC 6266 the old way...
        $this->assertMatchesRegularExpression('/filename="[^"]*'.preg_quote($expected, '/').'/', $disposition,
            "The quoted filename is missing or wrong: {$disposition}");

        // ...and RFC 5987, which wins wherever it is understood.
        $this->assertStringContainsString("filename*=UTF-8''", $disposition,
            "The RFC 5987 filename is missing: {$disposition}");
    }

    /**
     * The view route is the PDF.js viewer, pointed at the stream route.
     *
     * Serving the PDF `inline` and trusting the browser is what this replaces:
     * "inline" is a request a browser honours only if it has a PDF viewer and
     * is permitted to use one, and where it is not it downloads the file
     * instead. So the one button labelled "view" was the one whose promise
     * could be refused, silently. PDF.js draws the pages itself and needs
     * neither.
     */
    public function test_the_view_route_serves_the_pdfjs_viewer(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();

        $res = $this->actingAs($this->admin())->get(route('challans.view', $challan))->assertOk();

        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));
        $this->assertNull($res->headers->get('Content-Disposition'),
            'The view route is a page; a disposition would make the browser save it instead.');

        $res->assertSee('vendor/pdfjs-6.2.108/web/viewer.html', false)
            ->assertSee($challan->challan_no);

        // Root-relative, so the viewer's fetch stays same-origin and carries
        // the session cookie however the box is reached — proxy, LAN IP, or a
        // hostname that disagrees with APP_URL.
        $this->assertStringContainsString(
            rawurlencode('/challans/'.$challan->id.'/stream'),
            $res->getContent(),
            'An absolute stream URL turns the viewer fetch cross-origin and loses the session.',
        );
    }

    /**
     * The stream route really serves a PDF, not a page describing one.
     *
     * Its disposition is asserted by the `downloads` provider along with every
     * other named response; what is left here is the claim nothing else makes —
     * that the body is a PDF document rather than, say, a redirect to the login
     * screen rendered with a 200.
     */
    public function test_the_stream_route_serves_the_pdf_itself(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();

        $res = $this->actingAs($this->admin())->get(route('challans.stream', $challan))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $res->getContent());
    }

    /**
     * One renderer, one document: what is displayed and what is saved are the
     * same bytes under the same name, differing only in how they are delivered.
     *
     * This is the invariant the whole rework exists to establish. What it
     * replaces was a hand-written HTML twin of the voucher for the screen and
     * dompdf for the download — two typesetters for one financial document,
     * which drift, because dompdf supports a subset of CSS and nobody sees the
     * printed copy change when the screen one is tidied.
     */
    public function test_viewing_and_saving_are_the_same_document(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();

        $shown = $this->actingAs($this->admin())->get(route('challans.stream', $challan))->assertOk();
        $saved = $this->actingAs($this->admin())->get(route('challans.pdf', $challan))->assertOk();

        // The BYTES, and only the bytes. Both responses' filenames and content
        // types are pinned by the `downloads` provider, so asserting their
        // equality here would be arithmetic rather than a new fact — and it
        // would not catch the thing this test exists for. A future `stream()`
        // growing its own `Pdf::loadView(...)` with a different paper size or a
        // different Blade would keep both headers identical while the screen
        // and the parent's copy showed different documents.
        //
        // dompdf stamps a creation timestamp and a document id into every
        // render, so two renders of one voucher are never byte-identical. Those
        // two fields are normalised away; everything that is the document is
        // left alone.
        $normalise = fn (string $pdf) => preg_replace(
            ['/\/CreationDate\s*\([^)]*\)/', '/\/ModDate\s*\([^)]*\)/', '/\/ID\s*\[[^\]]*\]/'],
            '',
            $pdf,
        );

        $this->assertSame(
            $normalise($shown->getContent()),
            $normalise($saved->getContent()),
            'Viewing and saving produced different documents — there is more than one renderer again.',
        );
    }

    /**
     * The vendored viewer is COMMITTED, not merely sitting on this machine.
     *
     * `assertFileExists` was the first version of this test and it was worse
     * than useless: it passed on the laptop that vendored the files while a
     * bare `vendor/` in .gitignore — Composer's rule, unanchored, so it matches
     * at any depth — silently excluded `public/vendor/pdfjs` entirely. A clean
     * `git status`, a green suite, and every view button opening a blank frame
     * on CI and in production. The files being on disk is not the claim worth
     * asserting; the files being in the repository is.
     *
     * `git ls-files` rather than the filesystem, for exactly that reason.
     */
    public function test_the_vendored_pdf_viewer_is_committed(): void
    {
        // Derived from the constant the application actually uses. The version
        // is in this path deliberately — see DocumentResponse::VENDOR — so a
        // bump has to move the files, not just the string, and this is what
        // notices when only one of the two happened.
        $dir = 'public/'.DocumentResponse::VENDOR;

        $tracked = [];
        exec('git -C '.escapeshellarg(base_path()).' ls-files '.escapeshellarg($dir).' 2>/dev/null', $tracked);
        $tracked = array_flip($tracked);

        $this->assertNotEmpty($tracked,
            "git is tracking nothing under {$dir} — DocumentResponse::VENDOR was bumped without moving the files.");

        foreach ([
            'web/viewer.html',      // the application
            'web/viewer.mjs',
            'web/viewer.css',
            'build/pdf.mjs',        // the library it drives
            'build/pdf.worker.mjs', // and the worker that does the rendering
            'web/locale/locale.json',
            'web/institute.css',    // ours: hides the annotation editor
            'LICENSE',              // Apache-2.0. It is not ours to ship unmarked.
        ] as $file) {
            $path = $dir.'/'.$file;

            $this->assertFileExists(public_path(DocumentResponse::VENDOR.'/'.$file));
            $this->assertArrayHasKey($path, $tracked,
                "{$path} exists but git is not tracking it — check .gitignore.");
        }
    }

    /**
     * The annotation editor stays off a document of record.
     *
     * PDF.js v6 ships Draw, Text, Add signature and a Manage pages menu that
     * can delete pages and export a merged file. `web/institute.css` hides
     * them, and `web/viewer.html` has to link it — which is step 3 of the
     * upgrade instructions and therefore the step somebody will skip. The
     * symptom is an "Add signature" button appearing on a fee voucher, which
     * nothing else in the suite would notice.
     */
    public function test_the_viewer_does_not_offer_to_edit_the_document(): void
    {
        $html = file_get_contents(public_path(DocumentResponse::VENDOR.'/web/viewer.html'));
        $css = file_get_contents(public_path(DocumentResponse::VENDOR.'/web/institute.css'));

        $this->assertStringContainsString('institute.css', $html,
            'viewer.html lost the link to our stylesheet — re-apply step 3 of the upgrade notes.');

        foreach (['#editorModeButtons', '#viewsManagerToggleButton'] as $selector) {
            $this->assertStringContainsString($selector, $css);
            // The id has to still exist in Mozilla's markup, or the rule is
            // hiding nothing and the editor is back with no test failing.
            $this->assertStringContainsString('id="'.ltrim($selector, '#').'"', $html,
                "{$selector} is gone from viewer.html; the upgrade moved it and the editor is visible again.");
        }
    }

    /**
     * The challan's three routes, each carrying the same scope rule. The
     * receipt's three are covered by test_the_receipt_routes_view_stream_and_save,
     * which needs a Payment to point at.
     *
     * `stream` is the one worth testing hardest: it is reached by a fetch from
     * inside a viewer rather than by a click, which makes it the easiest route
     * to add and the easiest to forget to guard. An unguarded one would hand
     * any signed-in user every voucher in the institute.
     *
     * @return list<array{0:string}>
     */
    public static function documentRoutes(): array
    {
        return [
            'challan viewer' => ['challans.view'],
            'challan stream' => ['challans.stream'],
            'challan download' => ['challans.pdf'],
        ];
    }

    #[DataProvider('documentRoutes')]
    public function test_every_document_route_is_scoped(string $name): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // admin's

        $this->actingAs($this->admin())->get(route($name, $challan))->assertOk();
        $this->actingAs($this->officer())->get(route($name, $challan))->assertForbidden();
    }

    /**
     * A collected payment on the admin's challan, for the tests that need a
     * receipt to point at.
     *
     * One place, because the three lines it replaces were being written out
     * per-test and had to agree about the challan, the amount and how to pick
     * the payment back out.
     */
    private function aReceipt(): Payment
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // enrolled_by admin

        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');

        return $challan->fresh()->payments()->orderBy('id')->first();
    }

    /** The receipt's three routes, held to the voucher's rule. */
    public function test_the_receipt_routes_view_stream_and_save(): void
    {
        $payment = $this->aReceipt();

        $this->actingAs($this->admin())->get(route('payments.receipt.view', $payment))
            ->assertOk()
            ->assertSee('vendor/pdfjs-6.2.108/web/viewer.html', false);

        foreach (['payments.receipt.stream', 'payments.receipt'] as $name) {
            $this->actingAs($this->admin())->get(route($name, $payment))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }

        // And a receipt names the student, so it is no laxer than the voucher.
        foreach (['payments.receipt.view', 'payments.receipt.stream', 'payments.receipt'] as $name) {
            $this->actingAs($this->officer())->get(route($name, $payment))->assertForbidden();
        }
    }

    // ---- Invoices that bill no enrolment ------------------------------------

    /**
     * An invoice for a service — a desk, a certificate — with no admission.
     *
     * Built directly rather than imported, so these tests pin the SHAPE rather
     * than the importer that happens to produce it today. The counter will
     * raise these too.
     */
    private function charge(array $overrides = []): Challan
    {
        $officer = $this->officer();

        $challan = Challan::create($overrides + [
            'challan_no' => 'BBT-CH-2026-9001',
            'admission_id' => null,
            'student_id' => Student::first()->id,
            'raised_by' => $officer->id,
            'description' => 'Co-working Space',
            'base_amount' => 15000,
            'discount_amount' => 0,
            'net_amount' => 15000,
            'plan' => 'full',
            'due_date' => '2026-07-30',
            'status' => 'unpaid',
        ]);

        Payment::create([
            'challan_id' => $challan->id,
            'amount' => 15000,
            'method' => 'Cash',
            'received_by' => $officer->id,
            'received_at' => '2026-07-15',
        ]);

        return $challan->refresh();
    }

    /**
     * Every screen that lists invoices must survive one with no admission.
     *
     * The regression for a hard 500 on `/challans`: the list read
     * `$c->admission->student->name`, a charge has no admission, and the whole
     * screen died — not that row, everybody's. The same chain was written in
     * six places, so this walks all of them rather than only the one reported.
     *
     * Rendered rather than unit-tested, because the fault was never inside a
     * method anything called directly. It was the assumption, held in Blade,
     * that `admission` is always there.
     */
    public function test_every_screen_survives_an_invoice_with_no_enrolment(): void
    {
        $charge = $this->charge();
        $admin = $this->admin();

        foreach (['/dashboard', '/challans', '/students', '/reports'] as $screen) {
            $this->actingAs($admin)->get($screen)->assertOk();
        }

        // The drawer, where most of the `admission->` chains lived.
        $this->actingAs($admin)->get('/challans?open='.$charge->id)->assertOk();
    }

    /**
     * Both PDFs, whose controllers tested `admission->enrolled_by` directly —
     * a 500 rather than a permission decision on an invoice with no admission.
     */
    public function test_a_charge_prints_a_voucher_and_a_receipt(): void
    {
        $charge = $this->charge();

        $this->actingAs($this->admin())
            ->get(route('challans.pdf', $charge))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin())
            ->get(route('payments.receipt', $charge->payments->sole()))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * The officer who raised a charge may reach it; another officer may not.
     *
     * A charge has no `enrolled_by`, so the scope rule has to fall back to
     * `raised_by`. Without this the answer was an exception, which fails
     * *open* in the sense that matters: it tells you nothing about who is
     * allowed.
     */
    public function test_a_charge_is_scoped_to_the_officer_who_raised_it(): void
    {
        $charge = $this->charge();
        $other = User::where('username', 'fatimanoor')->firstOrFail();

        $this->actingAs($this->officer())->get(route('challans.pdf', $charge))->assertOk();
        $this->actingAs($other)->get(route('challans.pdf', $charge))->assertForbidden();
    }

    /** An overdue charge belongs on the dashboard beside an overdue fee. */
    public function test_an_overdue_charge_reaches_the_dashboard(): void
    {
        $this->charge(['due_date' => '2026-06-01', 'challan_no' => 'BBT-CH-2026-9002']);

        $this->actingAs($this->admin())->get('/dashboard')
            ->assertOk()
            ->assertSee('Co-working Space');
    }
}
