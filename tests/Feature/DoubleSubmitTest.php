<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Operation;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\ChallanActions;
use App\Services\Operations;
use App\Services\RegistrationService;
use App\Support\Format;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * GAP-08: a submit that arrives twice must take effect once.
 *
 * Every test here models the second click the way a browser actually produces
 * one — a SEPARATE request carrying the component snapshot as it was before the
 * first response came back. That is why each "second click" is a fresh
 * component seeded with the same token rather than a second `call()` on the
 * same instance: calling twice on one instance tests a sequence the browser
 * never sends, and would have passed against the broken code.
 */
class DoubleSubmitTest extends TestCase
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

    private function unpaidChallan(): Challan
    {
        return Challan::where('status', '!=', 'paid')->firstOrFail();
    }

    // ---- The mechanism itself ---------------------------------------------

    public function test_the_same_token_runs_the_work_once(): void
    {
        $ops = app(Operations::class);
        $ran = 0;

        $first = $ops->once('token-a', 'test.op', function () use (&$ran) {
            $ran++;

            return 'the value';
        });
        $second = $ops->once('token-a', 'test.op', function () use (&$ran) {
            $ran++;

            return 'the value';
        });

        $this->assertSame(1, $ran);
        $this->assertFalse($first->replayed);
        $this->assertSame('the value', $first->value);
        $this->assertTrue($second->replayed);
        $this->assertNull($second->value, 'A replay has nothing new to report.');
        $this->assertSame(1, Operation::count());
        $this->assertNotSame('token-a', Operation::first()->key,
            'The stored marker is a server-side derivation, never the client token verbatim.');
    }

    /**
     * The token is client-supplied — it has to be, to survive the round trip —
     * so it must not be able to satisfy an operation it did not mint.
     *
     * Storing it raw made one global namespace of every operation in the
     * system: a token burnt on `student.create` then made `once()` report a
     * payment as already recorded. No payments row, no audit row, and a green
     * "Part payment received · Rs 5,000 · Cash" on the officer's screen.
     */
    public function test_a_token_burnt_on_one_operation_cannot_silence_another(): void
    {
        $this->actingAs($this->admin());
        $ops = app(Operations::class);

        $ops->once('shared-token', 'student.create', fn () => 'burnt here');
        $second = $ops->once('shared-token', 'payment.record', fn () => 'must still run');

        $this->assertFalse($second->replayed, 'A different operation is not a replay.');
        $this->assertSame('must still run', $second->value);
    }

    public function test_one_users_token_cannot_silence_anothers_operation(): void
    {
        $ops = app(Operations::class);

        $this->actingAs($this->admin());
        $ops->once('same-token', 'payment.record', fn () => 'admin ran');

        $this->actingAs(User::where('username', 'aliraza')->firstOrFail());
        $second = $ops->once('same-token', 'payment.record', fn () => 'officer ran');

        $this->assertFalse($second->replayed, 'Tokens are scoped to the actor who minted them.');
        $this->assertSame('officer ran', $second->value);
    }

    public function test_a_different_token_runs_the_work_again(): void
    {
        $ops = app(Operations::class);
        $ran = 0;
        $work = function () use (&$ran) {
            $ran++;
        };

        $ops->once('token-a', 'test.op', $work);
        $ops->once('token-b', 'test.op', $work);

        $this->assertSame(2, $ran, 'Two genuinely separate operations must both run.');
    }

    public function test_an_empty_token_is_refused_rather_than_waved_through(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without an idempotency token');

        app(Operations::class)->once('', 'test.op', fn () => 'never');
    }

    /**
     * The property that stops the cure being worse than the disease.
     *
     * If the marker survived a failure, one dropped connection would poison
     * that token permanently: the officer would be locked out of recording a
     * payment that never actually happened, with nothing on screen to say why.
     */
    public function test_a_failed_operation_leaves_no_marker_so_it_can_be_retried(): void
    {
        $ops = app(Operations::class);

        try {
            $ops->once('token-c', 'test.op', fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Operation::count());

        $retry = $ops->once('token-c', 'test.op', fn () => 'worked this time');

        $this->assertFalse($retry->replayed);
        $this->assertSame('worked this time', $retry->value);
    }

    /**
     * The failure mode a guard must never have: reporting success for work
     * that did not happen.
     *
     * `$work` reaches unique indexes of its own. The first version of
     * `Operations::once()` wrapped the marker and the work in one `try`, so a
     * collision on `students.student_code` came back as `replayed: true` with
     * no marker row and no student — and the screen said "already on file".
     */
    public function test_a_constraint_inside_the_work_is_not_mistaken_for_a_replay(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $existing = Student::firstOrFail();

        try {
            app(Operations::class)->once('fresh-token', 'student.create', fn () => Student::create([
                'student_code' => $existing->student_code,   // collides, and has nothing to do with the token
                'type' => 'R', 'name' => 'Never Created',
                'phone' => '03001112222', 'created_by' => $admin->id,
            ]));
            $this->fail('The unique violation from the work should have surfaced.');
        } catch (UniqueConstraintViolationException) {
            // what we want: the real failure, not a fake replay
        }

        $this->assertSame(0, Operation::count(),
            'A failed operation must leave no marker, or the token is poisoned forever.');
        $this->assertSame(0, Student::where('name', 'Never Created')->count());
    }

    public function test_the_marker_records_who_and_what(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        app(Operations::class)->once('token-d', 'payment.record', fn () => null);

        $op = Operation::firstOrFail();
        $this->assertSame('payment.record', $op->name);
        $this->assertSame($admin->id, $op->user_id);
    }

    // ---- Payments: the money case -----------------------------------------

    public function test_a_double_clicked_part_payment_is_recorded_once(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $challan = $this->unpaidChallan();
        $balance = $challan->balance();
        $half = intdiv($balance, 2);

        // Click one.
        $page = Livewire::test('pages.challans')
            ->call('askPay', $challan->id)
            ->set('payAmount', $half)
            ->set('payMethod', 'Cash');

        $token = $page->get('opKeys')['pay'];
        $page->call('confirmPay');

        // Click two: already in flight when the first landed, so it carries the
        // snapshot from before — same payId, same amount, same token.
        Livewire::test('pages.challans')
            ->set('payId', $challan->id)
            ->set('payAmount', $half)
            ->set('payMethod', 'Cash')
            ->set('opKeys', ['pay' => $token])
            ->call('confirmPay');

        $this->assertSame(1, Payment::where('challan_id', $challan->id)->count());
        $this->assertSame($half, (int) Payment::where('challan_id', $challan->id)->sum('amount'));
        $this->assertSame($balance - $half, $challan->fresh()->balance(),
            'The rest of the fee is still owed; the phantom payment must not have settled it.');
        $this->assertNotSame('paid', $challan->fresh()->status);
    }

    public function test_the_swallowed_click_says_so_quietly(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $challan = $this->unpaidChallan();
        $half = intdiv($challan->balance(), 2);

        $page = Livewire::test('pages.challans')
            ->call('askPay', $challan->id)->set('payAmount', $half)->set('payMethod', 'Cash');
        $token = $page->get('opKeys')['pay'];

        // The real one carries no note at all.
        $page->call('confirmPay')->assertDispatched('bbt-toast',
            fn ($event, $params) => ! array_key_exists('note', $params));

        // The swallowed one says so — and reports the LEDGER, not the amount
        // this request happened to submit. A client-supplied token means the
        // request's own numbers prove nothing about what was recorded.
        Livewire::test('pages.challans')
            ->set('payId', $challan->id)
            ->set('payAmount', 999_999)              // a figure this request invented
            ->set('payMethod', 'Bank transfer')      // and a method it never used
            ->set('opKeys', ['pay' => $token])
            ->call('confirmPay')
            ->assertDispatched('bbt-toast', function ($event, $params) use ($half) {
                return $params['tone'] === 'ok'
                    && $params['note'] === 'Duplicate submission ignored'
                    && str_contains($params['msg'], Format::money($half))
                    && ! str_contains($params['msg'], '999,999')
                    && ! str_contains($params['msg'], 'Bank transfer');
            });
    }

    /**
     * The guard must not eat a real collection. A student paying a second
     * instalment goes through a freshly opened dialog, which mints a new token.
     */
    public function test_a_genuine_second_payment_still_lands(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $challan = $this->unpaidChallan();
        $quarter = intdiv($challan->balance(), 4);

        $page = Livewire::test('pages.challans');

        $page->call('askPay', $challan->id)->set('payAmount', $quarter)->set('payMethod', 'Cash')->call('confirmPay');
        $page->call('askPay', $challan->id)->set('payAmount', $quarter)->set('payMethod', 'Cash')->call('confirmPay');

        $this->assertSame(2, Payment::where('challan_id', $challan->id)->count(),
            'Two deliberate part payments are two operations, not a duplicate.');
        $this->assertSame($quarter * 2, (int) Payment::where('challan_id', $challan->id)->sum('amount'));
    }

    /**
     * A regression guard, and deliberately NOT a detector for the bug above.
     *
     * This assertion passes with the fix reverted, which is the whole reason
     * the defect was invisible: a phantom payment reduces the outstanding
     * balance by exactly the amount it invents, so `billed = received +
     * outstanding` keeps holding and every report stays internally consistent.
     * Nothing inside the database can tell that money was never handed over.
     * The only witness is the cash drawer, which is short — and counting it is
     * precisely what GAP-06 exists to add.
     *
     * Kept because the guard must not break reconciliation either.
     */
    /**
     * The token must rotate on success as well as on open.
     *
     * Both openers re-mint, so this line is belt-and-braces — but the trait's
     * docblock claims it and a claim nothing checks is worth exactly nothing.
     * Deleting the re-mint used to leave the whole file green.
     */
    public function test_the_token_rotates_after_a_successful_payment(): void
    {
        $this->actingAs($this->admin());
        $challan = $this->unpaidChallan();

        $page = Livewire::test('pages.challans')
            ->call('askPay', $challan->id)
            ->set('payAmount', intdiv($challan->balance(), 4))
            ->set('payMethod', 'Cash');

        $before = $page->get('opKeys')['pay'];
        $page->call('confirmPay');
        $after = $page->get('opKeys')['pay'];

        $this->assertNotSame($before, $after,
            'A consumed token must never still be sitting in the component.');
        $this->assertNotSame('', $after);
    }

    public function test_the_ledger_still_reconciles_after_a_swallowed_duplicate(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $challan = $this->unpaidChallan();
        $half = intdiv($challan->balance(), 2);

        $page = Livewire::test('pages.challans')
            ->call('askPay', $challan->id)->set('payAmount', $half)->set('payMethod', 'Cash');
        $token = $page->get('opKeys')['pay'];
        $page->call('confirmPay');

        Livewire::test('pages.challans')
            ->set('payId', $challan->id)->set('payAmount', $half)->set('payMethod', 'Cash')
            ->set('opKeys', ['pay' => $token])->call('confirmPay');

        $billed = (int) Challan::whereHas('admission', fn ($a) => $a->where('status', '!=', 'cancelled'))->sum('net_amount');
        $received = (int) Payment::sum('amount');
        $outstanding = Challan::whereHas('admission', fn ($a) => $a->where('status', '!=', 'cancelled'))
            ->get()->sum(fn (Challan $c) => $c->balance());

        $this->assertSame($billed, $received + $outstanding);
    }

    /** The registrations page carries the same drawer, so it needs the same guard. */
    public function test_the_registrations_drawer_payment_is_guarded_too(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $challan = $this->unpaidChallan();
        $half = intdiv($challan->balance(), 2);

        $page = Livewire::test('pages.registrations')
            ->call('askPay', $challan->id)->set('payAmount', $half)->set('payMethod', 'Cash');
        $token = $page->get('opKeys')['pay'];
        $page->call('confirmPay');

        Livewire::test('pages.registrations')
            ->set('payId', $challan->id)->set('payAmount', $half)->set('payMethod', 'Cash')
            ->set('opKeys', ['pay' => $token])->call('confirmPay');

        $this->assertSame(1, Payment::where('challan_id', $challan->id)->count());
    }

    /**
     * Found in a browser, not here — the suite could only ever see "status
     * 403", which looked like the guard working.
     *
     * When the first response lands before the second click (a fast connection,
     * which is most of them), the dialog has already closed and `payId` is
     * null. That used to reach `abort(403)`, and Livewire renders a 403 as a
     * full-screen "You do not have access to this screen" — over a payment that
     * had just succeeded. The officer is told they lack permission for
     * something they just did correctly.
     */
    public function test_a_second_click_after_the_dialog_closed_is_quietly_ignored(): void
    {
        $this->actingAs($this->admin());
        $challan = $this->unpaidChallan();

        Livewire::test('pages.challans')
            ->set('payId', null)
            ->set('payAmount', 5000)
            ->set('payMethod', 'Cash')
            ->call('confirmPay')
            ->assertStatus(200)
            ->assertNotDispatched('bbt-toast');

        $this->assertSame(0, Payment::where('challan_id', $challan->id)->count());
    }

    public function test_a_challan_outside_the_viewers_scope_is_still_a_403(): void
    {
        // The quiet return must not have swallowed the real authorization
        // check: an id that was actually submitted, for a challan this user
        // cannot see, is a scope violation and stays a 403.
        $officer = User::where('username', 'aliraza')->firstOrFail();
        $this->actingAs($officer);

        $unreachable = Challan::whereHas('admission', fn ($a) => $a->where('enrolled_by', '!=', $officer->id))->first();

        if (! $unreachable) {
            $this->markTestSkipped('The seed has no challan outside the officer scope.');
        }

        Livewire::test('pages.challans')
            ->set('payId', $unreachable->id)
            ->set('payAmount', 1000)
            ->set('payMethod', 'Cash')
            ->call('confirmPay')
            ->assertStatus(403);
    }

    public function test_a_second_cancel_click_is_also_quietly_ignored(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages.challans')
            ->set('cancelAdmId', null)
            ->set('cancelReason', 'anything')
            ->call('confirmCancel')
            ->assertStatus(200)
            ->assertNotDispatched('bbt-toast');
    }

    // ---- Students ----------------------------------------------------------

    public function test_a_double_clicked_new_student_is_created_once(): void
    {
        $this->actingAs($this->admin());
        $before = Student::count();

        $page = Livewire::test('pages.students')
            ->call('newStudent')
            ->set('fName', 'Ayesha Double')
            ->set('fPhone', '03001234567');
        $token = $page->get('opKeys')['student'];
        $page->call('saveStudent');

        Livewire::test('pages.students')
            ->set('fName', 'Ayesha Double')
            ->set('fPhone', '03001234567')
            ->set('opKeys', ['student' => $token])
            ->call('saveStudent');

        $this->assertSame(1, Student::where('name', 'Ayesha Double')->count());
        $this->assertSame($before + 1, Student::count());
    }

    public function test_a_genuine_second_student_still_gets_created(): void
    {
        $this->actingAs($this->admin());
        $page = Livewire::test('pages.students');

        $page->call('newStudent')->set('fName', 'First Person')->set('fPhone', '03001111111')->call('saveStudent');
        $page->call('newStudent')->set('fName', 'Second Person')->set('fPhone', '03002222222')->call('saveStudent');

        $this->assertSame(1, Student::where('name', 'First Person')->count());
        $this->assertSame(1, Student::where('name', 'Second Person')->count());
    }

    public function test_editing_a_student_twice_is_still_allowed(): void
    {
        $this->actingAs($this->admin());
        $student = Student::firstOrFail();

        $page = Livewire::test('pages.students');
        $page->call('editStudent', $student->id)->set('fName', 'Renamed Once')->call('saveStudent');
        $page->call('editStudent', $student->id)->set('fName', 'Renamed Twice')->call('saveStudent');

        $this->assertSame('Renamed Twice', $student->fresh()->name,
            'An update is idempotent, so the guard must not stand in its way.');
    }

    // ---- Registration: the worst case --------------------------------------

    public function test_a_double_submitted_new_student_registration_enrols_once(): void
    {
        $this->actingAs($this->admin());
        $course = Course::where('is_active', true)->firstOrFail();

        $students = Student::count();
        $admissions = Admission::count();
        $challans = Challan::count();

        $page = Livewire::test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'new')
            ->set('newName', 'Wizard Double')
            ->set('newPhone', '03009998877')
            ->set('courseIds', [$course->id]);
        $token = $page->get('opKeys')['enrol'];
        $page->call('submit');

        Livewire::test('pages.registrations')
            ->set('mode', 'new')
            ->set('newName', 'Wizard Double')
            ->set('newPhone', '03009998877')
            ->set('courseIds', [$course->id])
            ->set('opKeys', ['enrol' => $token])
            ->call('submit');

        $this->assertSame(1, Student::where('name', 'Wizard Double')->count(),
            'A double-submit used to create two people.');
        $this->assertSame($students + 1, Student::count());
        $this->assertSame($admissions + 1, Admission::count());
        $this->assertSame($challans + 1, Challan::count(),
            'And it used to bill the family twice.');
    }

    // ---- Toggles: a different failure with the same cause ------------------

    public function test_activating_a_course_twice_leaves_it_active(): void
    {
        $this->actingAs($this->admin());
        $course = Course::where('is_active', true)->firstOrFail();
        $course->update(['is_active' => false]);

        // Both clicks ask for the same state, so the second is a no-op rather
        // than a flip back. A toggle would have left this inactive.
        Livewire::test('pages.courses')->call('setActive', $course->id, true);
        Livewire::test('pages.courses')->call('setActive', $course->id, true);

        $this->assertTrue((bool) $course->fresh()->is_active);
    }

    // ---- Everything else stays where it was --------------------------------

    public function test_the_guard_writes_one_operation_row_per_real_submit(): void
    {
        $this->actingAs($this->admin());
        $challan = $this->unpaidChallan();
        $quarter = intdiv($challan->balance(), 4);

        $this->assertSame(0, Operation::count());

        $page = Livewire::test('pages.challans');
        $page->call('askPay', $challan->id)->set('payAmount', $quarter)->set('payMethod', 'Cash')->call('confirmPay');
        $page->call('askPay', $challan->id)->set('payAmount', $quarter)->set('payMethod', 'Cash')->call('confirmPay');

        $this->assertSame(2, Operation::where('name', 'payment.record')->count());
    }

    // ---- The detection command ---------------------------------------------

    public function test_the_duplicate_hunt_finds_a_planted_pair(): void
    {
        $challan = $this->unpaidChallan();
        $at = Carbon::parse('2026-07-15 10:00:00');

        // Exactly what the bug produced: same challan, same amount, same
        // method, three seconds apart.
        Payment::create(['challan_id' => $challan->id, 'amount' => 5000, 'method' => 'Cash', 'received_at' => $at]);
        Payment::create(['challan_id' => $challan->id, 'amount' => 5000, 'method' => 'Cash', 'received_at' => $at->copy()->addSeconds(3)]);

        $this->artisan('records:duplicates')
            ->expectsOutputToContain('2 suspected duplicate(s).')
            ->assertExitCode(0);
    }

    public function test_the_duplicate_hunt_leaves_a_deliberate_repeat_alone(): void
    {
        $challan = $this->unpaidChallan();
        $at = Carbon::parse('2026-07-15 10:00:00');

        // The same student paying the same amount twice in a day is normal.
        Payment::create(['challan_id' => $challan->id, 'amount' => 5000, 'method' => 'Cash', 'received_at' => $at]);
        Payment::create(['challan_id' => $challan->id, 'amount' => 5000, 'method' => 'Cash', 'received_at' => $at->copy()->addHours(4)]);

        $this->artisan('records:duplicates')->expectsOutputToContain('Nothing found.')->assertExitCode(0);
    }

    public function test_the_duplicate_hunt_finds_planted_students(): void
    {
        $at = Carbon::parse('2026-07-15 10:00:00');

        foreach (['R26-9001', 'R26-9002'] as $i => $code) {
            $s = Student::create([
                'student_code' => $code, 'type' => 'R', 'name' => 'Twice Entered',
                'phone' => '03005556666', 'created_by' => $this->admin()->id,
            ]);
            // `created_at` is not fillable, so passing it to create() was
            // silently dropped and both rows landed in the same second — the
            // window this test exists to exercise was never exercised.
            $s->forceFill(['created_at' => $at->copy()->addSeconds($i * 4)])->saveQuietly();
        }

        $this->artisan('records:duplicates')->expectsOutputToContain('Twice Entered')->assertExitCode(0);
    }

    /**
     * `diffInSeconds` is signed in Carbon 3, so an out-of-order pair gave a
     * negative gap and `-7200 <= 15` passed. Two students two hours apart were
     * reported as a double-click.
     */
    public function test_the_duplicate_hunt_does_not_flag_a_far_apart_pair(): void
    {
        $at = Carbon::parse('2026-07-15 10:00:00');

        foreach ([['R26-9101', 0], ['R26-9102', 7200]] as [$code, $offset]) {
            $s = Student::create([
                'student_code' => $code, 'type' => 'R', 'name' => 'Hours Apart',
                'phone' => '03007778888', 'created_by' => $this->admin()->id,
            ]);
            $s->forceFill(['created_at' => $at->copy()->addSeconds($offset)])->saveQuietly();
        }

        $this->artisan('records:duplicates')->expectsOutputToContain('Nothing found.')->assertExitCode(0);
    }

    /**
     * The signature lowercases and trims; the ORDER BY has to match or the
     * pair is never adjacent and never compared.
     */
    public function test_the_duplicate_hunt_sees_through_casing(): void
    {
        $at = Carbon::parse('2026-07-15 10:00:00');

        foreach ([['R26-9201', 'Sana Riaz', 0], ['R26-9202', 'sana riaz', 3]] as [$code, $name, $offset]) {
            $s = Student::create([
                'student_code' => $code, 'type' => 'R', 'name' => $name,
                'phone' => '03009990000', 'created_by' => $this->admin()->id,
            ]);
            $s->forceFill(['created_at' => $at->copy()->addSeconds($offset)])->saveQuietly();
        }

        $this->artisan('records:duplicates')->expectsOutputToContain('Sana Riaz')->assertExitCode(0);
    }

    public function test_the_seeded_database_is_clean(): void
    {
        // Everything the seeder writes lands in the same second, so a naive
        // "created at the same time" check would flag all of it. The signature
        // is name and phone, not timing alone.
        $this->artisan('records:duplicates')->expectsOutputToContain('Nothing found.')->assertExitCode(0);
    }

    public function test_the_window_is_configurable(): void
    {
        $challan = $this->unpaidChallan();
        $at = Carbon::parse('2026-07-15 10:00:00');

        Payment::create(['challan_id' => $challan->id, 'amount' => 5000, 'method' => 'Cash', 'received_at' => $at]);
        Payment::create(['challan_id' => $challan->id, 'amount' => 5000, 'method' => 'Cash', 'received_at' => $at->copy()->addSeconds(40)]);

        $this->artisan('records:duplicates')->expectsOutputToContain('Nothing found.');
        $this->artisan('records:duplicates', ['--seconds' => 60])->expectsOutputToContain('2 suspected duplicate(s).');
    }

    // ---- Money cannot be collected against a cancelled registration --------

    /**
     * Found while reviewing this change, and not a double-submit bug at all.
     *
     * `cancel()` refuses once money has been collected. Nothing refused the
     * reverse. Every money query in `Ledger` requires a live admission, so a
     * payment taken against a cancelled registration was banked, audited, and
     * then absent from billed, received, outstanding and every report — cash in
     * the drawer that no total in the system knew about.
     */
    public function test_money_cannot_be_collected_against_a_cancelled_registration(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $challan = Challan::where('status', '!=', 'paid')->whereDoesntHave('payments')->firstOrFail();
        app(ChallanActions::class)->cancel($challan->admission, $admin, 'testing');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cancelled');

        app(ChallanActions::class)->recordPayment($challan->fresh(), $admin, 1000, 'Cash');
    }

    public function test_the_registrations_screen_refuses_it_too(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $challan = Challan::where('status', '!=', 'paid')->whereDoesntHave('payments')->firstOrFail();
        app(ChallanActions::class)->cancel($challan->admission, $admin, 'testing');

        Livewire::test('pages.registrations')
            ->call('askPay', $challan->id)
            ->set('payAmount', 1000)
            ->set('payMethod', 'Cash')
            ->call('confirmPay')
            ->assertDispatched('bbt-toast', tone: 'err');

        $this->assertSame(0, Payment::where('challan_id', $challan->id)->count());
    }

    /** A grouped invoice stays collectable while any one enrolment lives. */
    public function test_a_grouped_invoice_is_still_collectable_when_one_course_is_cancelled(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $courses = Course::where('is_active', true)->take(2)->pluck('id')->all();
        $this->assertCount(2, $courses);

        $result = app(RegistrationService::class)->register($admin, [
            'course_ids' => $courses,
            'discount_pct' => 0,
            'generate_challans' => true,
            'new_student' => ['type' => 'R', 'name' => 'Grouped Person', 'guardian_name' => 'G', 'phone' => '03004445555', 'cnic' => ''],
        ]);

        $challan = $result['challans'][0];
        app(ChallanActions::class)->cancel($result['admissions'][1], $admin, 'dropped one course');

        app(ChallanActions::class)->recordPayment($challan->fresh(), $admin, 1000, 'Cash');

        $this->assertSame(1, Payment::where('challan_id', $challan->id)->count(),
            'One cancelled enrolment must not close the whole invoice.');
    }

    // ---- The guard cannot be forgotten -------------------------------------

    /**
     * The thing that stops this decaying.
     *
     * A sixth screen calling `recordPayment()` directly would compile, review
     * cleanly and silently have no protection. `Operations::once()` refuses an
     * empty token, but only for a screen that tries to use it — it cannot
     * notice a screen that never calls it at all. This can.
     */
    public function test_every_screen_that_writes_a_record_goes_through_the_guard(): void
    {
        // Method call => the service whose import proves it is the risky one.
        // Matched on the method rather than on `Service::class)->method(`,
        // because the call sites resolve the service into a local first:
        // students.blade.php reads `$service->create(...)`.
        $risky = [
            '->recordPayment(' => 'ChallanActions',
            '->register(' => 'RegistrationService',
            '->create(' => 'StudentService',
        ];

        $unguarded = [];
        $guarded = 0;

        // Volt pages plus the traits they compose in — `CollectsPayments`
        // holds the payment call for both screens that take money.
        $files = array_merge(
            glob(resource_path('views/livewire/*/*.blade.php')),
            glob(app_path('Support/Concerns/*.php')),
        );

        foreach ($files as $file) {
            $source = file_get_contents($file);

            foreach ($risky as $call => $service) {
                if (! str_contains($source, $service)) {
                    continue;
                }

                foreach (explode(';', $source) as $statement) {
                    if (! str_contains($statement, $call)) {
                        continue;
                    }
                    if (str_contains($statement, '->once(')) {
                        $guarded++;

                        continue;
                    }
                    $unguarded[] = basename($file).' → '.$call;
                }
            }
        }

        $this->assertSame([], $unguarded,
            "These screens write a record without Operations::once():\n  ".implode("\n  ", $unguarded));

        // Without this the test passes when the patterns stop matching
        // anything — which is exactly how it failed silently the first time.
        $this->assertSame(3, $guarded,
            'Expected 3 guarded call sites: payment (CollectsPayments, shared by both screens), '
            .'registration, student. Found '.$guarded.' — either a screen was removed, or the '
            .'patterns above have gone stale.');
    }
}
