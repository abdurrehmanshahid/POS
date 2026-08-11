<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AppNotification;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\Clock;
use App\Support\Contact;
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

            // Put them back in the order the officer picked them.
            //
            // `whereIn` returns rows in whatever order the engine likes, in
            // practice by id. The first course becomes the invoice's anchor,
            // which is the one the Challans list, the dues report and the
            // voucher's admission number all show, so leaving it to the engine
            // meant an officer who selected "Shopify Advanced" then "Shopify
            // Basics" got an invoice headed by Basics. It also keeps the
            // enrolment order matching the share loop further down.
            $courses = $courses->sortBy(
                fn (Course $course) => array_search($course->id, $courseIds, true)
            )->values();

            $student = $this->resolveStudent($actor, $data);

            $this->assertNotAlreadyEnrolled($student, $courses);

            $this->promoteIfContact($student, $actor);

            $due = Clock::today()->copy()->addDays(7)->toDateString();

            $admissions = [];
            $challans = [];

            foreach ($courses as $course) {
                $admissions[] = Admission::create([
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
            }

            // The wizard's "Generate fee challan(s) on submit" checkbox. The
            // enrolments are real either way; the fee is simply not billed yet.
            if ($issueChallans) {
                // ONE invoice for the whole registration, billing every course
                // on it, because that is how the institute actually invoices:
                // one document, one fee, one discount, one balance, with the
                // courses listed on it. Raising a separate challan per course
                // meant a three-course registration handed the student three
                // invoices to reconcile and three balances to chase.
                $base = (int) $courses->sum('fee');
                $discount = $pct > 0 ? (int) round($base * $pct / 100) : 0;

                $challan = Challan::create([
                    'challan_no' => $this->sequences->nextChallanNo(),
                    // The anchor. Every existing query reaches the student and
                    // the headline course through this.
                    'admission_id' => $admissions[0]->id,
                    // Stated directly as well, because a challan billing a
                    // non-course charge has no admission to be reached through
                    // and `Ledger::scopedChallans()` needs an owner either way.
                    'student_id' => $admissions[0]->student_id,
                    'raised_by' => $actor->id,
                    'base_amount' => $base,
                    'discount_amount' => $discount,
                    'discount_reason' => $discount > 0 ? $reason : null,
                    'discount_approved_by' => $discount > 0 ? $actor->id : null,
                    'net_amount' => $base - $discount,
                    'plan' => 'full',
                    'due_date' => $due,
                    'status' => 'unpaid',
                ]);

                // Each enrolment carries its own course's fee as its share, so
                // base_amount is exactly the sum of what the invoice bills and
                // revenue-by-course can credit every course rather than only
                // the one that happens to head the document.
                foreach ($courses as $i => $course) {
                    $admissions[$i]->update([
                        'challan_id' => $challan->id,
                        'billed_amount' => (int) $course->fee,
                    ]);
                }

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

    /**
     * A contact who enrols stops being a contact, here and nowhere else.
     *
     * This is the whole conversion lifecycle, and it is deliberately three
     * lines inside the transaction that creates the admissions rather than a
     * button somewhere. `kind` answers one question — "is this person someone we
     * teach?" — and an enrolment settles it. Leaving the decision to an operator
     * would mean a genuinely enrolled student sitting outside every student
     * count until somebody remembered, which is the same wrong number the column
     * was added to fix, only harder to notice because the record looks complete.
     *
     * There is no route back. A student who later rents a desk is still a
     * student; demoting them would erase the enrolment from the headline count
     * while the enrolment itself carries on existing.
     *
     * Audited, because it changes which reports a person appears in and there
     * would otherwise be no record that they were ever anything else.
     */
    private function promoteIfContact(Student $student, User $actor): void
    {
        if (! $student->isContact()) {
            return;
        }

        $student->update(['kind' => 'student']);

        Audit::record('Contact enrolled as a student', $actor, [
            'subject' => $student,
            'subject_label' => $student->student_code.' · '.$student->name,
            'field' => 'kind',
            'old_value' => 'contact',
            'new_value' => 'student',
        ]);
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

        // Blank means "not known", which is NULL, not '' — see Contact::optional
        // for why the CNIC column makes that distinction load-bearing. Shared
        // with StudentService so the two doors that create a student cannot
        // disagree about it, which they already have once.
        return Student::create([
            'student_code' => $this->sequences->nextStudentCode($type),
            'type' => $type,
            'name' => $new['name'],
            'guardian_name' => Contact::optional($new['guardian_name'] ?? null),
            'cnic' => Contact::optional($new['cnic'] ?? null),
            'phone' => $new['phone'],
            'created_by' => $actor->id,
        ]);
    }
}
