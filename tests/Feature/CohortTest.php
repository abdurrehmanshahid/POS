<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\User;
use App\Services\Cohorts;
use App\Services\RegistrationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Course batches: the intake a student's enrolment lands in. */
class CohortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function course(string $code): Course
    {
        return Course::where('code', $code)->firstOrFail();
    }

    // ---- Backward: opening a batch adopts the course's existing students ----

    public function test_opening_a_batch_adopts_the_courses_unbatched_enrolments(): void
    {
        $course = $this->course('SHOP-101');
        $existing = Admission::where('course_id', $course->id)->where('status', '!=', 'cancelled')->pluck('id');

        $this->assertNotEmpty($existing, 'Seed must have SHOP-101 enrolments for this to mean anything.');

        $cohort = app(Cohorts::class)->create($this->admin(), [
            'name' => 'Batch # 11',
            'course_id' => $course->id,
        ]);

        foreach ($existing as $id) {
            $this->assertSame($cohort->id, Admission::find($id)->cohort_id);
        }
    }

    /** A student who finished an earlier intake is not dragged into the new one. */
    public function test_opening_a_batch_does_not_steal_students_from_another_batch(): void
    {
        $course = $this->course('SHOP-101');
        $service = app(Cohorts::class);

        $first = $service->create($this->admin(), ['name' => 'Batch # 10', 'course_id' => $course->id]);
        $claimed = Admission::where('cohort_id', $first->id)->pluck('id');
        $this->assertNotEmpty($claimed);

        $second = $service->create($this->admin(), ['name' => 'Batch # 11', 'course_id' => $course->id]);

        foreach ($claimed as $id) {
            $this->assertSame($first->id, Admission::find($id)->cohort_id, 'Existing members stay put.');
        }
        $this->assertSame(0, $second->admissions()->count());
    }

    // ---- Forward: a new enrolment joins the open batch ----------------------

    public function test_a_new_enrolment_joins_the_open_batch_automatically(): void
    {
        $course = $this->course('WD-101');
        $cohort = app(Cohorts::class)->create($this->admin(), [
            'name' => 'Batch # 4',
            'course_id' => $course->id,
        ]);

        $result = app(RegistrationService::class)->register($this->officer(), [
            'new_student' => [
                'type' => 'R', 'name' => 'Batched Student', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 1112222', 'cnic' => '35201-7777777-7',
            ],
            'course_ids' => [$course->id],
        ]);

        $this->assertSame($cohort->id, $result['admissions'][0]->cohort_id);
    }

    public function test_an_enrolment_on_a_course_with_no_batch_simply_has_none(): void
    {
        $result = app(RegistrationService::class)->register($this->officer(), [
            'new_student' => [
                'type' => 'R', 'name' => 'Unbatched Student', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 3334444', 'cnic' => '35201-8888888-8',
            ],
            'course_ids' => [$this->course('GD-101')->id],
        ]);

        $this->assertNull($result['admissions'][0]->cohort_id);
    }

    // ---- One open intake per course ----------------------------------------

    public function test_only_one_batch_per_course_takes_students(): void
    {
        $course = $this->course('AI-201');
        $service = app(Cohorts::class);

        $first = $service->create($this->admin(), ['name' => 'Batch # 1', 'course_id' => $course->id]);
        $second = $service->create($this->admin(), ['name' => 'Batch # 2', 'course_id' => $course->id]);

        $this->assertFalse($first->fresh()->is_open, 'Opening the second closes the first.');
        $this->assertTrue($second->fresh()->is_open);
        $this->assertSame(1, Cohort::where('course_id', $course->id)->where('is_open', true)->count());
    }

    public function test_two_batches_on_one_course_cannot_share_a_name(): void
    {
        $course = $this->course('AI-201');
        $service = app(Cohorts::class);
        $service->create($this->admin(), ['name' => 'Batch # 1', 'course_id' => $course->id]);

        $this->expectException(RuntimeException::class);
        $service->create($this->admin(), ['name' => 'Batch # 1', 'course_id' => $course->id]);
    }

    public function test_a_batch_cannot_take_an_admission_from_another_course(): void
    {
        $cohort = app(Cohorts::class)->create($this->admin(), [
            'name' => 'Batch # 1', 'course_id' => $this->course('AI-201')->id,
        ]);
        $foreign = Admission::whereHas('course', fn ($q) => $q->where('code', 'SHOP-101'))->firstOrFail();

        $this->expectException(RuntimeException::class);
        app(Cohorts::class)->assign($foreign, $cohort, $this->admin());
    }

    // ---- Access -------------------------------------------------------------

    public function test_officers_cannot_reach_the_batches_screen(): void
    {
        $this->actingAs($this->officer())->get(route('cohorts'))->assertForbidden();
    }

    public function test_an_administrator_can_create_a_batch_from_the_screen(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages.cohorts')
            ->call('newCohort')
            ->set('fCourseId', $this->course('VE-101')->id)
            ->set('fName', 'Batch # 7')
            ->set('fCapacity', '20')
            ->call('save')
            ->assertSet('formOpen', false);

        $this->assertDatabaseHas('cohorts', ['name' => 'Batch # 7', 'capacity' => 20, 'is_open' => true]);
    }
}
