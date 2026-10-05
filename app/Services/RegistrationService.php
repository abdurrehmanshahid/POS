<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AppNotification;
use App\Models\Challan;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
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
    public function __construct(
        private Sequences $sequences,
        private Cohorts $cohorts,
        private Installments $installments,
    ) {}

    /**
     * @param  array{
     *   student_id?:int|null,
     *   walk_in_type?:string|null,
     *   new_student?:array{type:string,name:string,guardian_name:string,phone:string,cnic:string}|null,
     *   course_ids:array<int>,
     *   modules?:array<int,list<int>>,
     *   cohorts?:array<int,int|null>,
     *   discount_pct?:int,
     *   discount_reason?:string|null,
     *   certificate_amount?:int,
     *   generate_challans?:bool,
     *   plan?:string,
     *   installments?:list<array{amount:int,due_date:string}>
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

        // Bounded for the same reason as the discount: it arrives over the
        // wire, and a negative one would bill less than the tuition.
        $certificateAmount = array_key_exists('certificate_amount', $data)
            ? (int) $data['certificate_amount']
            : null;

        if ($certificateAmount !== null && $certificateAmount < 0) {
            throw new InvalidArgumentException('The certificate amount cannot be negative.');
        }

        // The method the institute expects, printed on the voucher. Blank is a
        // real answer — not every registration has agreed one yet — but a value
        // that is not on the list is not, for the same reason the discount is
        // bounded here: the wizard's buttons are a suggestion, and this value
        // arrives over the wire and ends up printed on a document a parent acts
        // on. `ChallanActions` applies the identical rule to `paid_via`, so the
        // two halves of the same question cannot come to disagree about what
        // the institute accepts.
        $method = trim((string) ($data['payment_method'] ?? '')) ?: null;

        if ($method !== null && ! in_array($method, config('institute.payment_methods'), true)) {
            throw new InvalidArgumentException('That is not a payment method the institute accepts.');
        }

        $issueChallans = (bool) ($data['generate_challans'] ?? true);

        // Keyed by course id, because the wizard asks these questions per
        // course: a three-course registration can take all of one, half of
        // another, and put the third into a different batch.
        //
        // Both default to "not stated", and not stating them is the behaviour
        // every caller had before modules and batch-picking existed: the whole
        // course, in whichever intake is open. That is what keeps the roll
        // importer and the existing tests working untouched.
        $moduleChoice = (array) ($data['modules'] ?? []);
        $cohortChoice = (array) ($data['cohorts'] ?? []);

        // A plan cannot be honoured without an invoice to hang it on. Silently
        // dropping it would tell the officer the student is on installments
        // when nothing was scheduled, which is the worst of the three
        // outcomes — worse than refusing, and far worse than billing.
        $wantsSplit = ($data['plan'] ?? 'full') === 'split';

        if ($wantsSplit && ! $issueChallans) {
            throw new InvalidArgumentException(
                'An installment plan needs a fee challan. Tick "Generate fee challan(s) on submit" or choose Full payment.'
            );
        }

        return DB::transaction(function () use (
            $actor, $data, $courseIds, $pct, $reason, $method, $issueChallans,
            $moduleChoice, $cohortChoice, $wantsSplit, $certificateAmount
        ) {
            // Resolve and validate the courses BEFORE creating the student, so a
            // registration that cannot proceed does not leave a person behind.
            //
            // `lockForUpdate` holds the rows for the duration, which is what
            // makes the capacity check below mean anything: without it two
            // officers can both read "1 seat left" and both take it.
            $courses = Course::query()->whereIn('id', $courseIds)->lockForUpdate()->get();

            $this->assertCoursesAreEnrollable($courses, $courseIds);

            // Loaded after the lock, not through `with()` on it: `lockForUpdate`
            // locks the rows the query returns, and eager loads run as their own
            // statements, so pulling modules in there would have locked nothing
            // extra while making the intent look stronger than it is.
            $courses->load('modules');

            $moduleChoice = $this->resolveModules($courses, $moduleChoice);
            $cohortChoice = $this->resolveCohorts($courses, $cohortChoice);

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

            $this->promoteIfContact($student, $actor);
            $this->promoteIfWalkIn($student, $actor, (string) ($data['walk_in_type'] ?? 'R'));

            $due = Clock::today()->copy()->addDays(7)->toDateString();

            $admissions = [];
            $challans = [];

            foreach ($courses as $course) {
                $admission = Admission::create([
                    'reg_no' => $this->sequences->nextAdmissionNo(),
                    'student_id' => $student->id,
                    'course_id' => $course->id,
                    // The batch the officer picked on the wizard, already
                    // checked against this course by resolveCohorts(). Null is
                    // valid and always was: not every course is taught in
                    // intakes, and one with no open batch has none to join.
                    'cohort_id' => $cohortChoice[$course->id] ?? null,
                    'enrolled_by' => $actor->id,
                    'status' => 'validated',
                ]);

                // Only for a course that is actually divided up. A course
                // priced whole has nothing to record here, and writing a row
                // per "module" it does not have would make `isPartial()` lie.
                foreach ($moduleChoice[$course->id] ?? [] as $module) {
                    $admission->modules()->create([
                        'course_module_id' => $module->id,
                        // Snapshot. See 2026_09_17_000002.
                        'billed_amount' => (int) $module->fee,
                    ]);
                }

                $admissions[] = $admission;
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
                // Per course, priced by what was actually bought: the whole
                // thing for a course with no modules, or the sum of the
                // modules taken for one that has them. `courses.fee` is no
                // longer the only answer, so summing it here would bill a
                // one-module student for the entire syllabus.
                $prices = $courses->mapWithKeys(fn (Course $course) => [
                    $course->id => $course->priceFor(
                        array_map(fn (CourseModule $m) => $m->id, $moduleChoice[$course->id] ?? [])
                    ),
                ]);

                $base = (int) $prices->sum();

                // What the officer typed on the wizard, which sends 0 when
                // "Add certificate fee" is unticked. Callers that do not say
                // (the tests, older scripts) keep the configured charge per
                // course. Snapshotted onto the challan below rather than
                // looked up at print time — see 2026_09_17_000003.
                $certificate = $certificateAmount
                    ?? (int) config('institute.certificate_fee') * $courses->count();
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
                    'certificate_amount' => $certificate,
                    // Outside the discount: a scholarship is negotiated on
                    // tuition and does not reduce what the certificate costs
                    // the institute to issue.
                    'net_amount' => $base - $discount + $certificate,
                    'plan' => 'full',
                    // What was AGREED. `paid_via` records what happened, and
                    // the voucher prefers that once money has arrived.
                    'payment_method' => $method,
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
                        'billed_amount' => (int) $prices[$course->id],
                    ]);
                }

                Audit::issued($challan, $actor);
                if ($discount > 0) {
                    Audit::discountApplied($challan, $actor);
                }

                // Scheduled last, so it is written against the invoice's final
                // net_amount. `Installments::schedule()` refuses a plan that
                // does not sum to the fee exactly, which is the check that
                // makes a discount and a plan safe to set in the same submit.
                if ($wantsSplit) {
                    $this->installments->schedule(
                        $challan,
                        $data['installments'] ?? $this->installments->defaultPlan($challan)
                    );

                    $challan->refresh();
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
     * Turn the officer's module ticks into checked CourseModule rows.
     *
     * Returns a map of course id => list<CourseModule>, holding an entry only
     * for courses that are actually divided into modules. A course priced
     * whole is absent, which is what the billing loop and the admission
     * fan-out both read as "there is nothing per-module to do here".
     *
     * Not stating a choice for a modular course means the FULL course, i.e.
     * every sellable module. That default is load-bearing: it is what lets the
     * roll importer, the tests and any other existing caller keep passing bare
     * `course_ids` and get the same bill they always got.
     *
     * @param  Collection<int,Course>  $courses
     * @param  array<int,list<int>>  $choice
     * @return array<int,list<CourseModule>>
     */
    private function resolveModules(Collection $courses, array $choice): array
    {
        $resolved = [];

        foreach ($courses as $course) {
            $sellable = $course->sellableModules();

            if ($sellable->isEmpty()) {
                // A course with no modules cannot be bought by the module. An
                // officer sending ids for one is working from a stale screen —
                // the course was priced whole after their wizard opened — and
                // billing them the whole fee while they believe they picked
                // parts is the silent overcharge this refuses to make.
                if (($choice[$course->id] ?? []) !== []) {
                    throw new InvalidArgumentException(
                        $course->title.' ('.$course->code.') is not sold by the module. Reopen the wizard and choose again.'
                    );
                }

                continue;
            }

            $wanted = array_values(array_unique(array_map('intval', $choice[$course->id] ?? [])));

            if ($wanted === []) {
                $resolved[$course->id] = $sellable->all();

                continue;
            }

            $picked = $sellable->whereIn('id', $wanted)->values();

            // Every id must land. A module retired or deleted between opening
            // the wizard and submitting it would otherwise just drop out of
            // the total, handing the student a cheaper invoice than the one
            // they agreed to and a voucher missing a line it should carry.
            if ($picked->count() !== count($wanted)) {
                throw new InvalidArgumentException(
                    'One or more selected modules of '.$course->title.' ('.$course->code
                    .') is no longer available. Reopen the wizard and choose again.'
                );
            }

            $resolved[$course->id] = $picked->all();
        }

        return $resolved;
    }

    /**
     * Settle which batch each enrolment joins.
     *
     * Three inputs, deliberately distinguished:
     *
     *   key absent   the officer was not asked — every caller that predates
     *                the wizard's batch picker. Falls back to the open intake,
     *                which is exactly what this service did before.
     *   key => null  the officer WAS asked and chose "no batch". Honoured as
     *                stated; a course can legitimately run without intakes.
     *   key => id    that batch, checked to belong to this course.
     *
     * Collapsing the first two would make the picker unable to express "none",
     * because clearing the dropdown would silently re-attach the open intake.
     *
     * @param  Collection<int,Course>  $courses
     * @param  array<int,int|null>  $choice
     * @return array<int,int|null>
     */
    private function resolveCohorts(Collection $courses, array $choice): array
    {
        $resolved = [];

        foreach ($courses as $course) {
            if (! array_key_exists($course->id, $choice)) {
                $resolved[$course->id] = $this->cohorts->openFor($course->id)?->id;

                continue;
            }

            $wanted = $choice[$course->id];

            if ($wanted === null || $wanted === '' || (int) $wanted === 0) {
                $resolved[$course->id] = null;

                continue;
            }

            $cohort = Cohort::where('course_id', $course->id)->find((int) $wanted);

            // Belonging is checked, not assumed. The id arrives over the wire,
            // and a batch of another course would put the student in an intake
            // they are not enrolled on — visible on the attendance register,
            // which reads batch membership, long before anybody looked at the
            // registration again.
            if (! $cohort) {
                throw new InvalidArgumentException(
                    'That batch does not belong to '.$course->title.' ('.$course->code.').'
                );
            }

            if ($cohort->isFull()) {
                throw new InvalidArgumentException(
                    $cohort->name.' is full. Choose another batch for '.$course->title.' ('.$course->code.').'
                );
            }

            $resolved[$course->id] = $cohort->id;
        }

        return $resolved;
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

    /**
     * A walk-in who registers becomes a student, and their walk-in code
     * (W26-####) is swapped for a Regular or Track one.
     *
     * Safe to replace because a walk-in has never been billed, so their W code
     * is on no challan or receipt; the audit row keeps the old one. Allocated
     * inside the registration transaction, so the first challan carries the
     * new code and a failed registration uses up no number. The series comes
     * from the wizard, because only now is the course known.
     */
    private function promoteIfWalkIn(Student $student, User $actor, string $type): void
    {
        if (! $student->isWalkIn()) {
            return;
        }

        $type = $type === 'T' ? 'T' : 'R';
        $walkInCode = $student->student_code;

        $student->update([
            'kind' => 'student',
            'type' => $type,
            'student_code' => $this->sequences->nextStudentCode($type),
        ]);

        Audit::record('Walk-in enrolled as a student', $actor, [
            'subject' => $student,
            'subject_label' => $student->student_code.' · '.$student->name,
            'field' => 'student_code',
            'old_value' => $walkInCode,
            'new_value' => $student->student_code,
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
