<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The instructor on a course is typed, and still stored as a relation.
 *
 * ---------------------------------------------------------------------------
 * What was wrong.
 * ---------------------------------------------------------------------------
 *
 * The field was a <select> over the `teachers` table, and nothing in the
 * application has ever created a teacher: no screen, no route, no service. The
 * table's only writer is `DemoDataSeeder`, which production is forbidden to
 * run. So on the institute's box the dropdown offered exactly one option —
 * "None" — and `courses.trainer_id` could never be anything but null. A field
 * whose only reachable value is the empty one, which is the same shape as the
 * `is_active` bug on staff accounts.
 *
 * ---------------------------------------------------------------------------
 * Why the relation survived the fix.
 * ---------------------------------------------------------------------------
 *
 * The obvious change — a `trainer_name` string column on `courses` — would have
 * meant a migration plus edits in the seven places that read the relation,
 * including the registration wizard, where a course can be found by typing its
 * instructor's name. Same typing experience, more churn, and that search lost.
 *
 * So the INPUT changed and the storage did not. These tests pin the part that
 * carries the risk: a typed name must resolve to ONE row however it is spelled,
 * because two rows for one person split their courses in that search and print
 * two spellings on two vouchers.
 */
class CourseInstructorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return $this->enrolTwoFactor(User::where('username', 'adminansar')->firstOrFail());
    }

    private function saveCourse(array $fields = []): Testable
    {
        $t = Livewire::actingAs($this->admin())->test('pages.courses')->call('addCourse');

        foreach (array_merge([
            'code' => 'QA-101', 'title' => 'QA Course', 'fee' => 5000, 'fStatus' => 'active',
        ], $fields) as $k => $v) {
            $t->set($k, $v);
        }

        return $t->call('save');
    }

    public function test_typing_a_new_name_creates_the_instructor_and_attaches_them(): void
    {
        $this->assertNull(Teacher::where('name', 'Nadia Khan')->first());

        $this->saveCourse(['trainerName' => 'Nadia Khan'])->assertHasNoErrors();

        $course = Course::where('code', 'QA-101')->firstOrFail();
        $this->assertSame('Nadia Khan', $course->trainer?->name);
    }

    /**
     * The whole reason this is not a plain string column.
     *
     * An officer typing "umer ali" on Tuesday and "Umer Ali" on Friday means one
     * person. Two rows would split that person's courses in the registration
     * wizard's search and put two spellings on two fee vouchers.
     */
    public function test_the_same_name_in_a_different_case_is_the_same_instructor(): void
    {
        $existing = Teacher::where('name', 'Umer Ali')->firstOrFail();

        $this->saveCourse(['code' => 'QA-102', 'trainerName' => 'umer   ALI'])->assertHasNoErrors();

        $this->assertSame($existing->id, Course::where('code', 'QA-102')->firstOrFail()->trainer_id);
        $this->assertSame(1, Teacher::where('name', 'Umer Ali')->count(), 'A second row was created for one person.');
    }

    public function test_leaving_it_blank_assigns_nobody(): void
    {
        $before = Teacher::count();

        $this->saveCourse(['code' => 'QA-103', 'trainerName' => '  '])->assertHasNoErrors();

        $this->assertNull(Course::where('code', 'QA-103')->firstOrFail()->trainer_id);
        $this->assertSame($before, Teacher::count(), 'Blank input invented an instructor.');
    }

    /** Editing an existing course shows the current name, not an id. */
    public function test_editing_loads_the_current_name_into_the_field(): void
    {
        $course = Course::whereNotNull('trainer_id')->with('trainer')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test('pages.courses')
            ->call('editCourse', $course->id)
            ->assertSet('trainerName', $course->trainer->name);
    }

    /** And changing it away leaves the course with nobody, not with the old name. */
    public function test_clearing_the_name_removes_the_instructor(): void
    {
        $course = Course::whereNotNull('trainer_id')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test('pages.courses')
            ->call('editCourse', $course->id)
            ->set('trainerName', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($course->fresh()->trainer_id);
    }

    /**
     * A removed instructor is restored, not duplicated.
     *
     * `Teacher` soft-deletes, so a plain lookup skips a trashed row and would
     * happily create a second one with the same name beside the hidden first.
     * Nobody finds that until the numbers stop adding up.
     */
    public function test_a_soft_deleted_instructor_is_restored_rather_than_duplicated(): void
    {
        $teacher = Teacher::where('name', 'Umer Ali')->firstOrFail();
        $teacher->delete();

        $this->saveCourse(['code' => 'QA-104', 'trainerName' => 'Umer Ali'])->assertHasNoErrors();

        $this->assertSame(1, Teacher::withTrashed()->where('name', 'Umer Ali')->count());
        $this->assertFalse($teacher->fresh()->trashed(), 'The instructor should have been restored.');
        $this->assertSame($teacher->id, Course::where('code', 'QA-104')->firstOrFail()->trainer_id);
    }

    /** Too long for the column is refused rather than truncated. */
    public function test_an_over_long_name_is_refused(): void
    {
        $this->saveCourse(['code' => 'QA-105', 'trainerName' => str_repeat('a', 121)])
            ->assertHasErrors('trainerName');

        $this->assertNull(Course::where('code', 'QA-105')->first());
    }
}
