<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\ChallanActions;
use App\Services\Installments;
use App\Services\RegistrationService;
use App\Services\Reporting;
use App\Support\Period;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Selling a course by the module, agreeing a payment plan, and naming the
 * batch — the three things a fee voucher could not say before.
 *
 * Time is frozen the same way {@see InstituteCoreTest} freezes it, because a
 * plan's default due dates are "today + 7" and "today + 37".
 */
class ModulesAndPlansTest extends TestCase
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

    private function student(): Student
    {
        return Student::firstOrFail();
    }

    /** DMM-101 divided into the institute's 45k / 30k / 45k split. */
    private function modularCourse(): Course
    {
        $course = Course::where('code', 'DMM-101')->firstOrFail();

        foreach ([[1, 'Module 1', 45000], [2, 'Module 2', 30000], [3, 'Module 3', 45000]] as [$seq, $title, $fee]) {
            $course->modules()->create(['seq' => $seq, 'title' => $title, 'fee' => $fee, 'is_active' => true]);
        }

        return $course->refresh()->load('modules');
    }

    // ---- Pricing -----------------------------------------------------------

    public function test_a_course_with_no_modules_is_still_priced_by_its_own_fee(): void
    {
        $course = Course::where('code', 'GD-101')->firstOrFail();

        // The whole catalogue looks like this, and must keep doing so.
        $this->assertFalse($course->hasModules());
        $this->assertSame((int) $course->fee, $course->priceFor());
        $this->assertSame((int) $course->fee, $course->priceFor([]));
    }

    public function test_the_full_course_costs_exactly_what_its_modules_add_up_to(): void
    {
        $course = $this->modularCourse();

        // There is no separate bundle price to drift out of step, which is the
        // whole reason full-course is defined this way rather than as a column.
        $this->assertSame(120000, $course->priceFor());
        $this->assertSame(120000, $course->priceFor(null));
    }

    public function test_taking_some_modules_bills_only_those(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();

        $this->assertSame(90000, $course->priceFor([$mods[0]->id, $mods[2]->id]));
        $this->assertSame(30000, $course->priceFor([$mods[1]->id]));
    }

    public function test_a_retired_module_is_no_longer_sellable_but_keeps_its_history(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'modules' => [$course->id => [$mods[1]->id]],
        ]);

        $mods[1]->update(['is_active' => false]);
        $course->refresh()->load('modules');

        // Gone from the catalogue...
        $this->assertCount(2, $course->sellableModules());
        $this->assertSame(90000, $course->priceFor());

        // ...but the enrolment that bought it still names it, at the price it
        // was sold for. This is what the snapshot exists to protect.
        $admission = $result['admissions'][0]->fresh('modules.module');
        $this->assertSame('Module 2', $admission->modules->first()->module->title);
        $this->assertSame(30000, (int) $admission->modules->first()->billed_amount);
    }

    public function test_the_price_is_snapshotted_so_a_re_price_cannot_rewrite_an_old_voucher(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'modules' => [$course->id => [$mods[0]->id]],
        ]);

        $mods[0]->update(['fee' => 60000]);

        $admission = $result['admissions'][0]->fresh('modules');
        $this->assertSame(45000, (int) $admission->modules->first()->billed_amount);
        $this->assertSame(45000, (int) $admission->billed_amount);
        $this->assertSame(45000, (int) $result['challans'][0]->fresh()->base_amount);
    }

    // ---- Registration ------------------------------------------------------

    public function test_registering_without_naming_modules_buys_the_whole_course(): void
    {
        $course = $this->modularCourse();

        // The roll importer and every pre-module caller pass bare course_ids.
        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
        ]);

        $admission = $result['admissions'][0]->fresh('modules.module');
        $this->assertCount(3, $admission->modules);
        $this->assertFalse($admission->isPartial());
        $this->assertSame('Full course', $admission->moduleLabel());
        $this->assertSame(120000, (int) $admission->billed_amount);
    }

    public function test_a_partial_purchase_is_labelled_by_the_modules_taken(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'modules' => [$course->id => [$mods[0]->id, $mods[2]->id]],
        ]);

        $admission = $result['admissions'][0]->fresh('modules.module');
        $this->assertTrue($admission->isPartial());
        $this->assertSame('Module 1, Module 3', $admission->moduleLabel());
        $this->assertSame(90000, (int) $admission->billed_amount);
    }

    public function test_module_ids_are_refused_for_a_course_that_is_not_sold_that_way(): void
    {
        $whole = Course::where('code', 'GD-101')->firstOrFail();
        $other = $this->modularCourse();
        $stray = $other->sellableModules()->first();

        // Silently billing the whole fee here is the overcharge this refuses.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not sold by the module');

        app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$whole->id],
            'modules' => [$whole->id => [$stray->id]],
        ]);
    }

    public function test_a_module_that_disappeared_mid_wizard_refuses_the_registration(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();
        $doomed = $mods[1]->id;
        $mods[1]->update(['is_active' => false]);

        // Dropping it from the total instead would hand the student a cheaper
        // invoice than the one they agreed to.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no longer available');

        app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'modules' => [$course->id => [$mods[0]->id, $doomed]],
        ]);
    }

    // ---- Batch -------------------------------------------------------------

    public function test_the_officer_can_name_the_batch_and_can_choose_none(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();
        $batch = Cohort::where('course_id', $course->id)->where('is_open', true)->firstOrFail();
        $service = app(RegistrationService::class);

        $chosen = $service->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'cohorts' => [$course->id => $batch->id],
        ]);
        $this->assertSame($batch->id, $chosen['admissions'][0]->cohort_id);

        // Explicitly stated as none, which must NOT fall back to the open
        // intake — that is the distinction resolveCohorts() exists to keep.
        $none = $service->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'cohorts' => [$course->id => null],
        ]);
        $this->assertNull($none['admissions'][0]->cohort_id);
    }

    public function test_not_asking_about_the_batch_still_joins_the_open_intake(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();
        $open = Cohort::where('course_id', $course->id)->where('is_open', true)->firstOrFail();

        // Every caller that predates the picker, the roll importer included.
        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
        ]);

        $this->assertSame($open->id, $result['admissions'][0]->cohort_id);
    }

    public function test_a_batch_belonging_to_another_course_is_refused(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();
        $foreign = Cohort::where('course_id', '!=', $course->id)->firstOrFail();

        // Otherwise the student turns up on another course's attendance
        // register, which is where this would first be noticed.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to');

        app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'cohorts' => [$course->id => $foreign->id],
        ]);
    }

    // ---- Payment plan ------------------------------------------------------

    public function test_a_registration_can_be_put_on_a_two_part_plan(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'plan' => 'split',
        ]);

        $challan = $result['challans'][0]->fresh('installments');
        $parts = $challan->installments->sortBy('seq')->values();

        $this->assertCount(2, $parts);
        $this->assertSame('split', $challan->plan);
        $this->assertSame((int) $challan->net_amount, (int) $parts->sum('amount'));
        $this->assertSame('2026-07-22', $parts[0]->due_date->toDateString());
        $this->assertSame('2026-08-21', $parts[1]->due_date->toDateString());
        // The challan's own date tracks the LAST part, so every existing query
        // reading challans.due_date still means "settled in full by".
        $this->assertSame('2026-08-21', $challan->due_date->toDateString());
    }

    public function test_a_plan_without_a_challan_to_hang_on_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a fee challan');

        app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [Course::where('code', 'SHOP-101')->firstOrFail()->id],
            'plan' => 'split',
            'generate_challans' => false,
        ]);
    }

    public function test_the_drawer_can_put_an_existing_challan_on_a_plan_and_take_it_off(): void
    {
        $challan = Challan::whereDoesntHave('installments')->where('status', 'unpaid')->firstOrFail();
        $net = (int) $challan->net_amount;

        Livewire::actingAs($this->admin())->test('pages.challans')
            ->call('askPlan', $challan->id)
            ->set('planAdvanceAmount', 5000)
            ->call('confirmPlan')
            ->assertSet('planError', '');

        $challan->refresh()->load('installments');
        $this->assertCount(2, $challan->installments);
        $this->assertSame('split', $challan->plan);
        $this->assertSame($net, (int) $challan->installments->sum('amount'));

        Livewire::actingAs($this->admin())->test('pages.challans')->call('clearPlan', $challan->id);

        $challan->refresh()->load('installments');
        $this->assertCount(0, $challan->installments);
        $this->assertSame('full', $challan->plan);
    }

    public function test_an_advance_at_or_above_the_whole_fee_is_refused(): void
    {
        $challan = Challan::whereDoesntHave('installments')->where('status', 'unpaid')->firstOrFail();

        $component = Livewire::actingAs($this->admin())->test('pages.challans')
            ->call('askPlan', $challan->id)
            ->set('planAdvanceAmount', (int) $challan->net_amount)
            ->call('confirmPlan');

        $this->assertStringContainsString('leave nothing for the last one', $component->get('planError'));
        // Refused, so nothing was written and the dialog stays open on the
        // number the officer typed rather than closing over a silent failure.
        $this->assertNotNull($component->get('planId'));
        $this->assertCount(0, $challan->fresh()->installments);
        $this->assertSame('full', $challan->fresh()->plan);
    }

    public function test_a_plan_may_have_three_parts(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
        ]);

        $challan = $result['challans'][0];
        $net = (int) $challan->net_amount;

        Livewire::actingAs($this->admin())->test('pages.challans')
            ->call('askPlan', $challan->id)
            ->set('planParts', 3)
            ->set('planAdvanceAmount', 5000)
            ->set('planSecondAmount', 6000)
            ->call('confirmPlan')
            ->assertSet('planError', '');

        $parts = $challan->fresh('installments')->installments->sortBy('seq')->values();

        $this->assertCount(3, $parts);
        $this->assertSame(5000, (int) $parts[0]->amount);
        $this->assertSame(6000, (int) $parts[1]->amount);
        // The last is derived, so the three sum to the fee exactly.
        $this->assertSame($net - 11000, (int) $parts[2]->amount);
        $this->assertSame($net, (int) $parts->sum('amount'));
        $this->assertSame('split', $challan->fresh()->plan);
    }

    public function test_a_three_part_plan_that_leaves_nothing_for_the_last_is_refused(): void
    {
        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [Course::where('code', 'SHOP-101')->firstOrFail()->id],
        ]);

        $challan = $result['challans'][0];

        $component = Livewire::actingAs($this->admin())->test('pages.challans')
            ->call('askPlan', $challan->id)
            ->set('planParts', 3)
            ->set('planAdvanceAmount', (int) $challan->net_amount - 1)
            ->set('planSecondAmount', 5000)
            ->call('confirmPlan');

        $this->assertStringContainsString('leave nothing for the last one', $component->get('planError'));
        $this->assertCount(0, $challan->fresh()->installments);
    }

    public function test_the_service_refuses_more_than_three_installments(): void
    {
        $challan = Challan::whereDoesntHave('installments')->where('status', 'unpaid')->firstOrFail();
        $quarter = intdiv((int) $challan->net_amount, 4);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('one to 3 installments');

        app(Installments::class)->schedule($challan, [
            ['amount' => (int) $challan->net_amount - 3 * $quarter, 'due_date' => '2026-07-22'],
            ['amount' => $quarter, 'due_date' => '2026-08-22'],
            ['amount' => $quarter, 'due_date' => '2026-09-22'],
            ['amount' => $quarter, 'due_date' => '2026-10-22'],
        ]);
    }

    // ---- The wizard --------------------------------------------------------

    public function test_the_wizard_prices_by_module_and_submits_modules_batch_and_plan(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();
        $batch = Cohort::where('course_id', $course->id)->where('is_open', true)->firstOrFail();

        $component = Livewire::actingAs($this->admin())->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'existing')
            ->set('pickedStudentId', $this->student()->id)
            ->set('step', 2)
            ->call('toggleCourse', $course->id);

        // Selecting the course seeds the full set and the open intake.
        $this->assertSame([$mods[0]->id, $mods[1]->id, $mods[2]->id], $component->get('moduleIds')[$course->id]);
        $this->assertSame((string) $batch->id, $component->get('batchIds')[$course->id]);
        $this->assertSame(120000, $component->instance()->baseFee());

        $component->call('toggleModule', $course->id, $mods[1]->id);
        $this->assertSame(90000, $component->instance()->baseFee());

        $component->set('withCertificate', true)->set('certificateAmount', '700');
        $component->set('payPlan', 'split');
        // The 700 certificate fee collected whole with the advance, plus half
        // the 90,000 tuition.
        $this->assertSame(45700, $component->get('planAdvance'));
        $this->assertSame(90700, $component->instance()->netFee());

        $before = Admission::count();
        $component->call('submit')->assertSet('wizardOpen', false);
        $this->assertSame($before + 1, Admission::count());

        $admission = Admission::latest('id')->with('modules.module', 'challan.installments')->first();
        $this->assertSame('Module 1, Module 3', $admission->moduleLabel());
        $this->assertSame($batch->id, $admission->cohort_id);
        $this->assertSame(90000, (int) $admission->billed_amount);
        $this->assertCount(2, $admission->challan->installments);
    }

    public function test_the_wizard_can_agree_a_three_part_plan(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        $component = Livewire::actingAs($this->admin())->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'existing')
            ->set('pickedStudentId', $this->student()->id)
            ->set('step', 2)
            ->call('toggleCourse', $course->id)
            ->call('setPlan', 'split', 3);

        $net = $component->instance()->netFee();
        $certificate = $component->instance()->certificateFee();

        // The first part carries the certificate charge whole AND whatever the
        // three-way division of the tuition left over, so the parts sum to the
        // fee exactly rather than a rupee under it.
        $later = intdiv($net - $certificate, 3);
        $this->assertSame($net - $later * 2, $component->get('planAdvance'));
        $this->assertGreaterThan($certificate + $later - 1, $component->get('planAdvance'));

        $admissionsBefore = Admission::count();
        $challansBefore = Challan::count();

        $component->call('submit')->assertSet('wizardOpen', false);

        // One student on a three-part plan is still ONE admission on ONE
        // challan. The parts are rows of that challan, never enrolments.
        $this->assertSame($admissionsBefore + 1, Admission::count());
        $this->assertSame($challansBefore + 1, Challan::count());

        $parts = Admission::latest('id')->first()->challan->installments->sortBy('seq')->values();
        $this->assertCount(3, $parts);
        $this->assertSame($net, (int) $parts->sum('amount'));
        $this->assertGreaterThanOrEqual($certificate, (int) $parts[0]->amount);
    }

    public function test_full_course_and_choose_modules_are_an_explicit_pair(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();

        $component = Livewire::actingAs($this->admin())->test('pages.registrations')
            ->call('openWizard')->set('step', 2)
            ->call('toggleCourse', $course->id);

        // Selecting the course means the whole of it.
        $this->assertCount(3, $component->get('moduleIds')[$course->id]);

        // "Choose modules" drops one rather than clearing the list, because an
        // empty list is read as the whole course and would mean the opposite.
        $component->call('chooseModules', $course->id);
        $this->assertSame([$mods[0]->id, $mods[1]->id], $component->get('moduleIds')[$course->id]);

        $component->call('takeWholeCourse', $course->id);
        $this->assertCount(3, $component->get('moduleIds')[$course->id]);
    }

    public function test_the_last_module_cannot_be_unticked(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();

        $component = Livewire::actingAs($this->admin())->test('pages.registrations')
            ->call('openWizard')->set('step', 2)
            ->call('toggleCourse', $course->id)
            ->call('toggleModule', $course->id, $mods[0]->id)
            ->call('toggleModule', $course->id, $mods[1]->id);

        $this->assertSame([$mods[2]->id], array_values($component->get('moduleIds')[$course->id]));

        // An empty list would be read as "the whole course" and bill 120,000,
        // which is the opposite of what the officer just asked for.
        $component->call('toggleModule', $course->id, $mods[2]->id);
        $this->assertSame([$mods[2]->id], array_values($component->get('moduleIds')[$course->id]));
    }

    public function test_dropping_a_course_forgets_its_modules_and_batch(): void
    {
        $course = $this->modularCourse();

        $component = Livewire::actingAs($this->admin())->test('pages.registrations')
            ->call('openWizard')->set('step', 2)
            ->call('toggleCourse', $course->id)
            ->call('toggleCourse', $course->id);

        $this->assertArrayNotHasKey($course->id, $component->get('moduleIds'));
        $this->assertArrayNotHasKey($course->id, $component->get('batchIds'));
    }

    // ---- Certificate charge ------------------------------------------------

    public function test_the_wizard_bills_no_certificate_unless_it_is_ticked(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        Livewire::actingAs($this->admin())->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'existing')
            ->set('pickedStudentId', $this->student()->id)
            ->set('step', 2)
            ->call('toggleCourse', $course->id)
            ->call('submit')
            ->assertSet('wizardOpen', false);

        $challan = Admission::latest('id')->first()->challan;
        $this->assertSame(0, (int) $challan->certificate_amount);
        $this->assertSame((int) $challan->base_amount, (int) $challan->net_amount);
    }

    public function test_the_wizard_bills_the_certificate_amount_typed_once_per_challan(): void
    {
        $courses = Course::whereIn('code', ['WD-101', 'AI-201'])->pluck('id')->all();

        $component = Livewire::actingAs($this->admin())->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'existing')
            ->set('pickedStudentId', $this->student()->id)
            ->set('step', 2);
        foreach ($courses as $id) { $component->call('toggleCourse', $id); }

        $component->set('withCertificate', true)->set('certificateAmount', '1250')
            ->call('submit')
            ->assertSet('wizardOpen', false);

        $challan = Admission::latest('id')->first()->challan;
        // What was typed, not 700 per course.
        $this->assertSame(1250, (int) $challan->certificate_amount);
        $this->assertSame((int) $challan->base_amount + 1250, (int) $challan->net_amount);
    }

    public function test_a_ticked_certificate_with_no_amount_stops_the_wizard(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        Livewire::actingAs($this->admin())->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'existing')
            ->set('pickedStudentId', $this->student()->id)
            ->set('step', 2)
            ->call('toggleCourse', $course->id)
            ->set('withCertificate', true)
            ->call('next')
            ->assertSet('step', 2)
            ->assertSet('wizErrors.certificate', 'Enter the certificate amount in whole rupees, or untick "Add certificate fee".');
    }

    public function test_the_certificate_is_charged_once_per_course_and_is_not_discounted(): void
    {
        $courses = Course::whereIn('code', ['WD-101', 'AI-201'])->pluck('id')->all();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => $courses,
            'discount_pct' => 50,
            'discount_reason' => 'Scholarship',
        ]);

        $challan = $result['challans'][0];

        $this->assertSame(40000, $challan->base_amount);
        $this->assertSame(20000, $challan->discount_amount);
        // Two courses, two certificates.
        $this->assertSame(1400, $challan->certificate_amount);
        // Half price on the tuition; the certificate charge in full.
        $this->assertSame(21400, $challan->net_amount);
    }

    public function test_a_module_only_enrolment_still_pays_the_certificate(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'modules' => [$course->id => [$mods[1]->id]],
        ]);

        $challan = $result['challans'][0];
        $this->assertSame(30000, $challan->base_amount);
        $this->assertSame(700, $challan->certificate_amount);
        $this->assertSame(30700, $challan->net_amount);
    }

    public function test_the_certificate_charge_is_collected_with_the_first_installment(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'plan' => 'split',
        ]);

        $challan = $result['challans'][0]->fresh('installments');
        $parts = $challan->installments->sortBy('seq')->values();
        $tuition = (int) $challan->net_amount - (int) $challan->certificate_amount;

        // The charge is incurred on enrolment, so it is not spread over both.
        $this->assertSame(700 + (int) ceil($tuition / 2), (int) $parts[0]->amount);
        $this->assertSame((int) $challan->net_amount, (int) $parts->sum('amount'));
        $this->assertGreaterThan((int) $parts[1]->amount, (int) $parts[0]->amount);
    }

    public function test_an_advance_below_the_certificate_charge_is_refused(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
        ]);

        $component = Livewire::actingAs($this->admin())->test('pages.challans')
            ->call('askPlan', $result['challans'][0]->id)
            ->set('planAdvanceAmount', 500)
            ->call('confirmPlan');

        $this->assertStringContainsString('certificate charges', $component->get('planError'));
        $this->assertCount(0, $result['challans'][0]->fresh()->installments);
    }

    public function test_a_challan_raised_before_the_charge_existed_still_totals_what_it_did(): void
    {
        // Every seeded challan predates it. Their arithmetic must not move.
        foreach (Challan::whereNotNull('admission_id')->get() as $challan) {
            $this->assertSame(0, (int) $challan->certificate_amount);
            $this->assertSame(
                (int) $challan->base_amount - (int) $challan->discount_amount,
                (int) $challan->net_amount,
                $challan->challan_no.' changed total.'
            );
        }
    }

    public function test_setting_the_charge_to_zero_stops_charging_it(): void
    {
        config(['institute.certificate_fee' => 0]);

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [Course::where('code', 'SHOP-101')->firstOrFail()->id],
        ]);

        $challan = $result['challans'][0];
        $this->assertSame(0, (int) $challan->certificate_amount);
        $this->assertSame((int) $challan->base_amount, (int) $challan->net_amount);
    }

    public function test_the_charge_is_not_credited_to_a_course_as_revenue(): void
    {
        $admin = $this->admin();
        $reporting = app(Reporting::class);
        $period = Period::resolve('today');

        $before = $reporting->revenueByCourse($admin, $period, 50)->sum('total');

        $result = app(RegistrationService::class)->register($admin, [
            'student_id' => $this->student()->id,
            'course_ids' => Course::whereIn('code', ['WD-101', 'AI-201'])->pluck('id')->all(),
        ]);

        $invoice = $result['challans'][0];
        app(ChallanActions::class)->markPaid($invoice, $admin, 'Cash');

        $earned = $reporting->revenueByCourse($admin, $period, 50)->sum('total') - $before;

        // The institute banked 41,400. The courses earned 40,000 of it; the
        // rest is the certificate charge, which no course earned.
        $this->assertSame(41400, (int) $invoice->net_amount);
        $this->assertSame(40000, $earned);
        $this->assertSame((int) $invoice->net_amount, $earned + (int) $invoice->certificate_amount);

        // And the drawer's per-enrolment figure agrees with the report rather
        // than quietly including the charge.
        $shares = collect($result['admissions'])->sum(fn ($a) => $a->refresh()->netShare());
        $this->assertSame(40000, $shares);
    }

    public function test_the_voucher_names_the_charge_the_subtotal_and_the_modules(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();
        $batch = Cohort::where('course_id', $course->id)->where('is_open', true)->firstOrFail();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'modules' => [$course->id => [$mods[0]->id, $mods[2]->id]],
            'cohorts' => [$course->id => $batch->id],
            'plan' => 'split',
        ]);

        $challan = $result['challans'][0];

        // Rendered directly, because `challans.view` returns the PDF.js viewer
        // shell and the document itself is a PDF — neither is greppable. The
        // eager loads are copied from ChallanController::render() so this
        // exercises the same object graph the real route builds.
        $html = view('challans.pdf', [
            'challan' => $challan->fresh()->load(
                'student', 'raiser',
                'admission.student', 'admission.course.trainer', 'admission.cohort', 'admission.enroller',
                'admissions.course', 'admissions.cohort', 'admissions.modules.module',
                'discountApprover', 'installments', 'payments',
            ),
            'settings' => Setting::current(),
        ])->render();

        // The three things the voucher could not say before.
        $this->assertStringContainsString('Certificate charges', $html);
        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringContainsString('Module 1, Module 3', $html);
        $this->assertStringContainsString($batch->name, $html);
        $this->assertStringContainsString('1st Installment', $html);
        $this->assertStringContainsString('certificate charges', $html);

        // And the real route still serves it.
        $this->actingAs($this->admin())
            ->get(route('challans.pdf', $challan))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // ---- The career tracks -------------------------------------------------

    public function test_the_seven_career_tracks_exist_and_are_sold_by_the_module(): void
    {
        $tracks = Course::where('code', 'like', 'TRK-%')->with('modules')->orderBy('code')->get();

        $this->assertCount(7, $tracks, 'The site advertises seven career tracks.');

        foreach ($tracks as $track) {
            $modules = $track->sellableModules();

            $this->assertCount(3, $modules, $track->code.' should carry three modules.');
            $this->assertSame([45000, 30000, 45000], $modules->pluck('fee')->all(), $track->code);
            $this->assertSame(['Module 1', 'Module 2', 'Module 3'], $modules->pluck('title')->all(), $track->code);
            $this->assertSame(120000, $track->priceFor(), $track->code.' full track.');
            // `fee` agrees with what the modules add up to, so the row still
            // quotes the right price if every module is ever retired.
            $this->assertSame(120000, (int) $track->fee, $track->code);
            $this->assertTrue($track->is_active, $track->code);
        }
    }

    public function test_the_tracks_did_not_disturb_the_courses_they_grew_out_of(): void
    {
        // A track is a different product from the older course it resembles,
        // and renaming or re-pricing that course would rewrite what its
        // students already bought.
        foreach ([
            'ODOO-301' => 80000,
            'CS-101' => 30000,
            'GAI-601' => 60000,
            'WD-101' => 20000,
            'GD-101' => 20000,
            'DMM-601' => 40000,
        ] as $code => $fee) {
            $course = Course::where('code', $code)->firstOrFail();

            $this->assertSame($fee, (int) $course->fee, $code.' was re-priced.');
            $this->assertFalse($course->hasModules(), $code.' was divided up without being asked.');
            $this->assertSame($fee, $course->priceFor(), $code.' no longer quotes its own fee.');
        }
    }

    public function test_one_module_of_a_track_bills_that_module_plus_the_certificate(): void
    {
        $track = Course::where('code', 'TRK-GAAI')->with('modules')->firstOrFail();
        $modules = $track->sellableModules();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$track->id],
            'modules' => [$track->id => [$modules[1]->id]],
        ]);

        $admission = $result['admissions'][0]->fresh('modules.module');
        $challan = $result['challans'][0];

        $this->assertSame('Module 2', $admission->moduleLabel());
        $this->assertSame(30000, (int) $admission->billed_amount);
        $this->assertSame(700, (int) $challan->certificate_amount);
        $this->assertSame(30700, (int) $challan->net_amount);
    }

    // ---- The courses screen ------------------------------------------------

    public function test_the_courses_screen_creates_and_prices_modules(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        Livewire::actingAs($this->admin())->test('pages.courses')
            ->call('editCourse', $course->id)
            ->call('addModule')->call('addModule')
            ->set('modules.0.title', 'Foundations')->set('modules.0.fee', 45000)
            ->set('modules.1.title', 'Advanced')->set('modules.1.fee', 30000)
            ->call('save');

        $course->refresh()->load('modules');
        $this->assertSame(['Foundations', 'Advanced'], $course->sellableModules()->pluck('title')->all());
        $this->assertSame([1, 2], $course->sellableModules()->pluck('seq')->all());
        $this->assertSame(75000, $course->priceFor());
    }

    public function test_a_module_nobody_bought_is_deleted_but_a_sold_one_is_retired(): void
    {
        $course = $this->modularCourse();
        $mods = $course->sellableModules();

        // Sell the second one.
        app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $this->student()->id,
            'course_ids' => [$course->id],
            'modules' => [$course->id => [$mods[1]->id]],
        ]);

        // Remove modules 2 and 3 from the form.
        $component = Livewire::actingAs($this->admin())->test('pages.courses')->call('editCourse', $course->id);
        $this->assertCount(3, $component->get('modules'));
        $component->call('removeModule', 2)->call('removeModule', 1)->call('save');

        $course->refresh()->load('modules');
        $this->assertSame(['Module 1'], $course->sellableModules()->pluck('title')->all());

        // Never sold: gone.
        $this->assertNull(CourseModule::find($mods[2]->id));
        // Sold: still there, just not on offer. The voucher that lists it has
        // to keep adding up.
        $sold = CourseModule::find($mods[1]->id);
        $this->assertNotNull($sold);
        $this->assertFalse($sold->is_active);
    }

    public function test_a_module_without_a_name_or_a_fee_is_refused(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        Livewire::actingAs($this->admin())->test('pages.courses')
            ->call('editCourse', $course->id)
            ->call('addModule')
            ->set('modules.0.title', '')->set('modules.0.fee', 0)
            ->call('save')
            ->assertHasErrors(['modules.0.title', 'modules.0.fee']);

        $this->assertCount(0, $course->refresh()->sellableModules());
    }
}
