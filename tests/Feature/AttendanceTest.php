<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\User;
use App\Services\Attendances;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Taking the register.
 *
 * The `attendances` table shipped with the original schema and then sat inert
 * for the whole of the build: no screen, no service, no writer. These cover the
 * capture path that was missing, and the constraint that keeps a double-saved
 * register from doubling every percentage the table will ever produce.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['institute.today' => '2026-07-15']);
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return $this->enrolTwoFactor(User::where('username', 'adminansar')->firstOrFail());
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    private function course(string $code = 'AI-201'): Course
    {
        return Course::where('code', $code)->firstOrFail();
    }

    // ---- The roster ----------------------------------------------------------

    public function test_the_roster_is_the_courses_live_enrolments(): void
    {
        $course = $this->course();
        $roster = app(Attendances::class)->roster($course);

        $this->assertGreaterThan(0, $roster->count());

        // Nobody appears twice, and everybody on it has a live admission.
        $this->assertSame($roster->count(), $roster->unique('id')->count());
        foreach ($roster as $student) {
            $this->assertTrue(
                $student->admissions()->where('course_id', $course->id)->where('status', '!=', 'cancelled')->exists()
            );
        }
    }

    public function test_a_cancelled_enrolment_drops_off_the_register(): void
    {
        $course = $this->course();
        $service = app(Attendances::class);

        $before = $service->roster($course);
        $victim = $before->first();

        $victim->admissions()->where('course_id', $course->id)
            ->update(['status' => 'cancelled']);

        $after = $service->roster($course);

        $this->assertCount($before->count() - 1, $after);
        $this->assertFalse($after->contains('id', $victim->id));
    }

    // ---- Recording -----------------------------------------------------------

    public function test_a_register_is_recorded_with_one_audit_row_for_the_day(): void
    {
        $course = $this->course();
        $service = app(Attendances::class);
        $roster = $service->roster($course);

        $marks = $roster->mapWithKeys(fn ($s, $i) => [$s->id => $i === 0 ? 'absent' : 'present'])->all();

        $written = $service->record($this->admin(), $course, null, '2026-07-15', $marks);

        $this->assertSame($roster->count(), $written);
        $this->assertSame($roster->count(), Attendance::count());

        // One row per register, not per student: thirty rows a day would bury
        // every other event in the activity log.
        $audit = AuditLog::where('action', 'Attendance recorded')->get();
        $this->assertCount(1, $audit);
        $this->assertSame(1, $audit->first()->context['absent']);
    }

    public function test_saving_the_same_register_twice_corrects_it_rather_than_doubling_it(): void
    {
        $course = $this->course();
        $service = app(Attendances::class);
        $roster = $service->roster($course);
        $student = $roster->first();

        $service->record($this->admin(), $course, null, '2026-07-15',
            $roster->mapWithKeys(fn ($s) => [$s->id => 'present'])->all());

        $rows = Attendance::count();

        // The correction: that student was actually away.
        $service->record($this->admin(), $course, null, '2026-07-15',
            $roster->mapWithKeys(fn ($s) => [$s->id => $s->is($student) ? 'absent' : 'present'])->all());

        $this->assertSame($rows, Attendance::count(), 'Re-saving must not write a second set of marks.');
        $this->assertSame('absent', Attendance::where('student_id', $student->id)->firstOrFail()->status);
        $this->assertCount(1, AuditLog::where('action', 'Attendance corrected')->get());
    }

    public function test_the_database_refuses_two_marks_for_one_student_on_one_day(): void
    {
        $course = $this->course();
        $student = app(Attendances::class)->roster($course)->first();

        Attendance::create([
            'course_id' => $course->id, 'student_id' => $student->id,
            'session_date' => '2026-07-15', 'status' => 'present', 'marked_by' => $this->admin()->id,
        ]);

        $this->expectException(QueryException::class);

        Attendance::create([
            'course_id' => $course->id, 'student_id' => $student->id,
            'session_date' => '2026-07-15', 'status' => 'absent', 'marked_by' => $this->admin()->id,
        ]);
    }

    public function test_the_register_cannot_be_taken_for_a_future_date(): void
    {
        $course = $this->course();
        $service = app(Attendances::class);
        $marks = $service->roster($course)->mapWithKeys(fn ($s) => [$s->id => 'present'])->all();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('future date');

        $service->record($this->admin(), $course, null, '2026-08-20', $marks);
    }

    public function test_a_student_not_on_the_course_cannot_be_marked(): void
    {
        $course = $this->course();
        $service = app(Attendances::class);

        // A student enrolled on a different course entirely.
        $stranger = $service->roster($this->course('SHOP-101'))
            ->reject(fn ($s) => $service->roster($course)->contains('id', $s->id))
            ->first();

        $this->assertNotNull($stranger, 'The seed must contain a student on SHOP-101 but not AI-201.');

        $marks = $service->roster($course)->mapWithKeys(fn ($s) => [$s->id => 'present'])->all();
        $marks[$stranger->id] = 'present';

        $service->record($this->admin(), $course, null, '2026-07-15', $marks);

        $this->assertNull(
            Attendance::where('course_id', $course->id)->where('student_id', $stranger->id)->first(),
            'A crafted student id must be ignored, not marked.'
        );
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $course = $this->course();
        $service = app(Attendances::class);
        $student = $service->roster($course)->first();

        $this->expectException(RuntimeException::class);

        $service->record($this->admin(), $course, null, '2026-07-15', [$student->id => 'maybe']);
    }

    // ---- Rates ----------------------------------------------------------------

    public function test_leave_is_excluded_from_the_rate_rather_than_counted_against_it(): void
    {
        $course = $this->course();
        $service = app(Attendances::class);
        $roster = $service->roster($course);

        $this->assertGreaterThanOrEqual(2, $roster->count());

        $service->record($this->admin(), $course, null, '2026-07-15', [
            $roster[0]->id => 'present',
            $roster[1]->id => 'leave',
        ]);

        $rate = $service->ratesByCourse(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'))
            ->firstWhere('code', $course->code);

        // One present, one on leave, zero absent: 100%, not 50%.
        $this->assertSame(100, $rate->rate);
        $this->assertSame(1, $rate->leave);
    }

    public function test_a_course_with_no_register_taken_has_no_rate(): void
    {
        $rates = app(Attendances::class)
            ->ratesByCourse(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        // Nothing recorded at all, so nothing to report. A 0% row here would
        // read as "nobody turned up", which is the fabrication this whole
        // feature exists to replace.
        $this->assertTrue($rates->isEmpty());
    }

    // ---- The screen ------------------------------------------------------------

    public function test_the_screen_saves_a_register(): void
    {
        $course = $this->course();
        $officer = $this->officer();

        Livewire::actingAs($officer)
            ->test('pages.attendance')
            ->set('courseId', $course->id)
            ->set('date', '2026-07-15')
            ->call('markAll', 'absent')
            ->call('save')
            ->assertSet('error', '')
            ->assertSet('alreadyTaken', true);

        $this->assertGreaterThan(0, Attendance::where('status', 'absent')->count());
        $this->assertSame(0, Attendance::where('status', 'present')->count());
    }

    public function test_the_screen_defaults_everyone_to_present(): void
    {
        $course = $this->course();

        $component = Livewire::actingAs($this->officer())
            ->test('pages.attendance')
            ->set('courseId', $course->id);

        // Marking the exceptions is the job. Defaulting to absent would mean
        // twenty-eight taps to record a normal day in a class of thirty.
        foreach ($component->get('marks') as $status) {
            $this->assertSame('present', $status);
        }
    }

    public function test_a_user_without_the_permission_cannot_reach_the_screen(): void
    {
        $officer = $this->officer();
        $officer->role->permissions()->where('permission_key', 'attendance.manage')->delete();

        $this->actingAs($officer->refresh())->get('/attendance')->assertForbidden();
    }
}
