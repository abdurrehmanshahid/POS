<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\Student;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\Attendances;
use App\Services\Installments;
use App\Services\StudentFreezes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Freezing a student pauses them, and unfreezing picks them up where they
 * stopped: same courses, same fees, unpaid deadlines moved on by the days away.
 */
class StudentFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // The day count runs off the real clock, so drive it with travelTo().
        config(['institute.today' => null]);
        $this->travelTo('2026-07-01 12:00:00');
    }

    private function admin(): User
    {
        return User::where('username', 'adminansar')->firstOrFail();
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    /** Unpaid, net 25,000, no collections against it. */
    private function challan(): Challan
    {
        return Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();
    }

    private function student(): Student
    {
        return Student::findOrFail($this->challan()->student_id);
    }

    public function test_freezing_needs_its_own_permission(): void
    {
        $this->assertTrue($this->admin()->can('students.freeze'));
        $this->assertFalse($this->officer()->can('students.freeze'));

        Livewire::actingAs($this->officer())
            ->test('pages.students')
            ->call('askFreeze')
            ->assertForbidden();
    }

    public function test_an_admin_freezes_and_unfreezes_from_the_student_drawer(): void
    {
        $student = $this->student();

        $page = Livewire::actingAs($this->admin())
            ->test('pages.students')
            ->call('viewStudent', $student->id)
            ->call('askFreeze')
            ->set('freezeReason', '')
            ->call('freezeStudent')
            ->assertHasErrors('freezeReason');

        $this->assertFalse($student->fresh()->isFrozen(), 'A reason is required.');

        $page->set('freezeReason', 'Exams')->call('freezeStudent')->assertHasNoErrors();

        $student->refresh();
        $this->assertTrue($student->isFrozen());
        $this->assertSame('Exams', $student->freeze_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Student frozen', 'subject_id' => $student->id]);

        $page->call('unfreezeStudent', $student->id);

        $this->assertFalse($student->fresh()->isFrozen());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Student unfrozen', 'subject_id' => $student->id]);
    }

    public function test_a_frozen_student_is_off_the_register_until_unfrozen(): void
    {
        $student = $this->student();
        $course = $student->admissions()->where('status', '!=', 'cancelled')->firstOrFail()->course;
        $register = app(Attendances::class);

        $this->assertTrue($register->roster($course)->contains('id', $student->id));

        app(StudentFreezes::class)->freeze($this->admin(), $student, 'Travelling');
        $this->assertFalse($register->roster($course)->contains('id', $student->id));

        app(StudentFreezes::class)->unfreeze($this->admin(), $student);
        $this->assertTrue($register->roster($course)->contains('id', $student->id));
    }

    public function test_unfreezing_moves_unpaid_deadlines_on_by_the_days_frozen(): void
    {
        $challan = $this->challan();
        app(Installments::class)->schedule($challan, [
            ['amount' => 5000, 'due_date' => '2026-06-20'],   // already missed before the freeze
            ['amount' => 10000, 'due_date' => '2026-07-10'],
            ['amount' => 10000, 'due_date' => '2026-08-10'],
        ]);

        app(StudentFreezes::class)->freeze($this->admin(), $this->student(), 'Medical leave');

        $this->travelTo('2026-07-15 12:00:00');   // 14 days later
        $result = app(StudentFreezes::class)->unfreeze($this->admin(), $this->student());

        $this->assertSame(14, $result['days']);
        $this->assertSame(2, $result['moved']);

        $dates = $challan->installments()->orderBy('seq')->pluck('due_date')
            ->map(fn ($d) => $d->toDateString())->all();
        $this->assertSame(['2026-06-20', '2026-07-24', '2026-08-24'], $dates,
            'A deadline missed before the freeze stays missed; the rest move on 14 days.');
        $this->assertSame('2026-08-24', $challan->fresh()->due_date->toDateString(),
            'The challan still tracks its last instalment.');
        $this->assertSame(25000, $challan->fresh()->net_amount, 'Same fee.');
    }

    public function test_unfreezing_the_same_day_moves_nothing(): void
    {
        $challan = $this->challan();
        $before = $challan->due_date?->toDateString();

        app(StudentFreezes::class)->freeze($this->admin(), $this->student(), 'Changed their mind');
        $result = app(StudentFreezes::class)->unfreeze($this->admin(), $this->student());

        $this->assertSame(0, $result['moved']);
        $this->assertSame($before, $challan->fresh()->due_date?->toDateString());
    }

    public function test_the_super_admin_can_freeze_and_unfreeze(): void
    {
        $su = SuperAdmin::firstOrFail();
        $su->forceFill(['password' => 'Owner@Pass1'])->save();
        $student = $this->student();

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.students')
            ->call('askFreeze', $student->id)
            ->set('dangerSecret', 'Owner@Pass1')
            ->set('dangerReason', 'Requested by the family')
            ->call('confirmDanger');

        $this->assertSame('Requested by the family', $student->fresh()->freeze_reason);

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.students')
            ->call('askUnfreeze', $student->id)
            ->set('dangerSecret', 'Owner@Pass1')
            ->call('confirmDanger');

        $this->assertFalse($student->fresh()->isFrozen());
    }
}
