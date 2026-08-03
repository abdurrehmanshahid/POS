<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AppNotification;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The registration wizard submit (spec §7.5). Multi-course fan-out: one
 * admission AND one challan per selected course, so each enrolment stays
 * independently auditable. Money is always derived; discounts require a reason.
 */
class RegistrationService
{
    public function __construct(private Sequences $sequences, private Cohorts $cohorts) {}

    /**
     * @param  array{
     *   student_id?:int|null,
     *   new_student?:array{type:string,name:string,guardian_name:string,phone:string,cnic:string}|null,
     *   course_ids:array<int>,
     *   discount_pct?:int,
     *   discount_reason?:string|null,
     *   generate_challans?:bool
     * }  $data
     * @return array{student:Student, admissions:list<Admission>, challans:list<Challan>}
     */
    public function register(User $actor, array $data): array
    {
        $pct = (int) ($data['discount_pct'] ?? 0);
        $reason = trim((string) ($data['discount_reason'] ?? '')) ?: null;
        $courseIds = array_values(array_unique($data['course_ids'] ?? []));

        if ($courseIds === []) {
            throw new InvalidArgumentException('Select at least one course.');
        }
        // The wizard's slider already bounds this, but a slider is a suggestion,
        // not a constraint: the pct arrives over the wire and a tampered request
        // can carry anything. Above 100 the derived discount exceeds the base and
        // net_amount goes negative into an unsignedInteger column, which either
        // throws at the driver or silently wraps and breaks the reporting
        // invariant that billed = received + outstanding.
        if ($pct < 0 || $pct > 100) {
            throw new InvalidArgumentException('Discount must be between 0 and 100 percent.');
        }
        if ($pct > 0 && $reason === null) {
            throw new InvalidArgumentException('A discount requires a reason.');
        }

        $issueChallans = (bool) ($data['generate_challans'] ?? true);

        return DB::transaction(function () use ($actor, $data, $courseIds, $pct, $reason, $issueChallans) {
            // Resolve and validate the courses BEFORE creating the student, so a
            // registration that cannot proceed does not leave a person behind.
            //
            // `lockForUpdate` holds the rows for the duration, which is what
            // makes the capacity check below mean anything: without it two
            // officers can both read "1 seat left" and both take it.
            $courses = Course::query()->whereIn('id', $courseIds)->lockForUpdate()->get();

            $this->assertCoursesAreEnrollable($courses, $courseIds);

            $student = $this->resolveStudent($actor, $data);

            $this->assertNotAlreadyEnrolled($student, $courses);

            $due = Clock::today()->copy()->addDays(7)->toDateString();

            $admissions = [];
            $challans = [];

            foreach ($courses as $course) {
                $admission = Admission::create([
                    'reg_no' => $this->sequences->nextAdmissionNo(),
                    'student_id' => $student->id,
                    'course_id' => $course->id,
                    // Joins whichever batch of this course is currently taking
                    // students. Null when the course runs no batches, which is
                    // valid: not every course is taught in intakes.
                    'cohort_id' => $this->cohorts->openFor($course->id)?->id,
                    'enrolled_by' => $actor->id,
                    'status' => 'validated',
                ]);

                $admissions[] = $admission;

                // The wizard's "Generate fee challan(s) on submit" checkbox.
                // It used to be decorative: a challan was raised either way, so
                // unticking it changed nothing while promising otherwise. The
                // enrolment is still real, the fee is simply not billed yet.
                if (! $issueChallans) {
                    continue;
                }

                $base = (int) $course->fee;
                $discount = $pct > 0 ? (int) round($base * $pct / 100) : 0;

                $challan = Challan::create([
                    'challan_no' => $this->sequences->nextChallanNo(),
                    'admission_id' => $admission->id,
                    'base_amount' => $base,
                    'discount_amount' => $discount,
                    'discount_reason' => $discount > 0 ? $reason : null,
                    'discount_approved_by' => $discount > 0 ? $actor->id : null,
                    'net_amount' => $base - $discount,
                    'plan' => 'full',
                    'due_date' => $due,
                    'status' => 'unpaid',
                ]);

                Audit::issued($challan, $actor);
                if ($discount > 0) {
                    Audit::discountApplied($challan, $actor);
                }

                $challans[] = $challan;
            }

            AppNotification::create([
                'type' => 'enrol',
                'title' => 'New enrolment created',
                'sub' => $student->name.', '.count($courses).' course'.(count($courses) === 1 ? '' : 's'),
                'student_id' => $student->id,
                'challan_id' => $challans[0]->id ?? null,
            ]);

            return ['student' => $student, 'admissions' => $admissions, 'challans' => $challans];
        });
    }

    /**
     * Every requested course must exist, be active, and have a seat free.
     *
     * All three were previously unchecked here. The wizard blocks a full course
     * at selection time, but selection and submission are separate requests: a
     * course with one seat left can fill in between, and any caller that is not
     * the wizard had no guard at all. Capacity is a promise made to the trainer
     * about room size, so it belongs where the enrolment is actually written.
     *
     * @param  Collection<int,Course>  $courses
     * @param  list<int>  $requestedIds
     */
    private function assertCoursesAreEnrollable(Collection $courses, array $requestedIds): void
    {
        // A course deleted between opening the wizard and submitting it silently
        // disappeared from the result set, and the loop below simply ran fewer
        // times. With one course selected that produced a student record with no
        // admission at all, plus a notification announcing an enrolment that
        // never happened.
        if ($courses->count() !== count($requestedIds)) {
            throw new InvalidArgumentException(
                'One or more of the selected courses no longer exists. Reopen the wizard and choose again.'
            );
        }

        foreach ($courses as $course) {
            if (! $course->is_active) {
                throw new InvalidArgumentException(
                    $course->title.' ('.$course->code.') is no longer taking enrolments.'
                );
            }

            if ($course->isFull()) {
                throw new InvalidArgumentException(
                    $course->title.' ('.$course->code.') is full, all '.$course->capacity.' seats are taken.'
                );
            }
        }
    }

    /**
     * Refuse to enrol somebody onto a course they are already on.
     *
     * The database enforces this too (see the migration adding
     * `admissions_live_enrolment_unique`), and the index is what actually makes
     * it true under concurrency. This check exists so the officer reads
     * "Maha Asim is already enrolled on Shopify" instead of a driver-level
     * integrity error, and so the whole registration is refused before any of it
     * is written rather than part-way through a multi-course fan-out.
     *
     * Cancelled enrolments do not count. Somebody who dropped the course in
     * March is entitled to take it again.
     *
     * @param  Collection<int,Course>  $courses
     */
    private function assertNotAlreadyEnrolled(Student $student, Collection $courses): void
    {
        $existing = Admission::query()
            ->where('student_id', $student->id)
            ->whereIn('course_id', $courses->pluck('id'))
            ->where('status', '!=', 'cancelled')
            ->pluck('course_id')
            ->all();

        if ($existing === []) {
            return;
        }

        $clash = $courses->whereIn('id', $existing)
            ->map(fn (Course $c) => $c->title.' ('.$c->code.')')
            ->implode(', ');

        throw new InvalidArgumentException(
            $student->name.' is already enrolled on '.$clash
            .'. Cancel the existing registration first if this one is meant to replace it.'
        );
    }

    private function resolveStudent(User $actor, array $data): Student
    {
        if (! empty($data['student_id'])) {
            // Scoped, not a bare findOrFail. An officer may only enrol a student
            // they are allowed to see; without this the id travels from the
            // client and reaches any student in the institute.
            return Student::query()->visibleTo($actor)->findOrFail($data['student_id']);
        }

        $new = $data['new_student'] ?? null;
        if (! $new) {
            throw new InvalidArgumentException('No student provided.');
        }

        $type = $new['type'] === 'T' ? 'T' : 'R';

        return Student::create([
            'student_code' => $this->sequences->nextStudentCode($type),
            'type' => $type,
            'name' => $new['name'],
            'guardian_name' => $new['guardian_name'],
            'cnic' => $new['cnic'],
            'phone' => $new['phone'],
            'created_by' => $actor->id,
        ]);
    }
}
