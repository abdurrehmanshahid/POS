<?php

namespace App\Services\Import;

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Services\Audit;
use App\Services\Installments;
use App\Services\Sequences;
use App\Support\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stage 6, and the only stage that writes.
 *
 * One transaction per row. A spreadsheet line becomes a student, one or more
 * admissions, one invoice covering them, the payments that were actually
 * collected, and — where money is still owed against a named instalment — a
 * schedule. Any failure rolls the whole line back, because half a line is worse
 * than none of it: a student with no admission is invisible, and an admission
 * with no invoice is a free course.
 *
 * Per row rather than per file on purpose. 478 rows in one transaction means
 * one bad row at number 400 discards 399 good ones, and re-running to get them
 * back is exactly the situation `import_key` exists to make safe.
 */
class RollPersister
{
    /**
     * How an imported collection arrived, where the roll does not say.
     *
     * "Unrecorded" rather than a new word like "Imported", because
     * `Reporting::byPaymentMethod()` already emits exactly this label for a
     * payment with no method (`$r->method ?: 'Unrecorded'`) — it is the term
     * the reporting layer uses for this case, not a sixth value invented here
     * and unknown to `config('institute.payment_methods')`.
     *
     * It stays outside that config on purpose: a counter must pick a real
     * method, and `ChallanActions` refuses anything not in the list.
     */
    private const METHOD = 'Unrecorded';

    /** @var array<string, Cohort> Batches created or found during this run. */
    private array $cohorts = [];

    public function __construct(
        private readonly Sequences $sequences,
        private readonly Installments $installments,
    ) {}

    /**
     * @return array{imported:int, failed:list<array{line:int, error:string}>}
     */
    public function persist(array $rows): array
    {
        $this->ensureBatches($rows);

        $imported = 0;
        $failed = [];

        foreach ($rows as $row) {
            if (! $row->isImportable()) {
                continue;
            }

            try {
                DB::transaction(fn () => $this->one($row));
                $imported++;
            } catch (Throwable $e) {
                // Recorded and skipped rather than aborting the run: the
                // operator wants the 470 rows that work and a list of the eight
                // that do not, not a run that stops at the first surprise.
                $failed[] = ['line' => $row->line, 'error' => $e->getMessage()];
            }
        }

        return ['imported' => $imported, 'failed' => $failed];
    }

    /**
     * Create every batch the importable rows need, before any row transaction.
     *
     * Batches are reference data, not a student's money, and creating them
     * inside a row's transaction was actively wrong: the run-scoped memo cached
     * the Cohort object, the row then rolled back and took the cohort row with
     * it, and every later row on the same course and batch wrote a `cohort_id`
     * pointing at nothing and died on the foreign key. One bad row took the
     * whole batch down with it.
     *
     * `withTrashed()` because `cohorts` soft-deletes while the unique index on
     * (course_id, name) still counts the deleted rows, so `firstOrCreate` alone
     * would try to insert over a tombstone and fail. Restoring is the right
     * answer: the institute is re-importing the batch it deleted.
     *
     * @param  list<RollRow>  $rows
     */
    private function ensureBatches(array $rows): void
    {
        foreach ($rows as $row) {
            if (! $row->isImportable() || $row->batchName === '') {
                continue;
            }

            foreach ($row->courses as $course) {
                $key = $course->id.'|'.$row->batchName;

                $this->cohorts[$key] ??= $row->cohorts[$course->id]
                    ?? tap(Cohort::withTrashed()->firstOrCreate(
                        ['course_id' => $course->id, 'name' => $row->batchName],
                        ['is_open' => false, 'created_by' => $row->officer->id],
                    ), fn (Cohort $c) => $c->trashed() ? $c->restore() : null);
            }
        }
    }

    private function one(RollRow $row): void
    {
        $student = $this->student($row);
        $challan = $this->challan($row, $student);

        $this->collect($row, $challan);
        $this->schedule($row, $challan);

        Audit::record('Student imported', $row->officer, [
            'subject' => $student,
            'subject_label' => $student->student_code.' · '.$student->name,
            'field' => 'import',
            'new_value' => $challan->challan_no,
            'context' => [
                'line' => $row->line,
                'courses' => implode(', ', array_map(fn ($c) => $c->code, $row->courses)),
                'received' => $row->totalReceived,
                'outstanding' => $row->balance,
            ],
        ]);
    }

    /**
     * Reuse the person if this file already created them, otherwise mint one.
     *
     * Matched on the import key of an existing admission rather than on name:
     * the roll contains 59 names shared by more than one row and no CNIC to
     * tell them apart, so matching on name would merge two strangers into one
     * student and pool their fees. A second line for the same person is
     * therefore imported as a second student unless it is literally the same
     * enrolment, which is the conservative direction to be wrong in — two
     * records for one person can be merged later; one record fusing two people
     * cannot be unpicked once money lands on it.
     */
    private function student(RollRow $row): Student
    {
        $existing = Admission::query()
            ->whereIn('import_key', array_values($row->importKeys))
            ->with('student')
            ->first();

        if ($existing?->student) {
            return $existing->student;
        }

        return Student::create([
            'student_code' => $this->sequences->nextStudentCode('R'),
            'type' => 'R',
            'name' => $row->name,
            'guardian_name' => null,
            'cnic' => null,
            'phone' => Contact::normalizePhone($row->phone),
            'created_by' => $row->officer->id,
        ]);
    }

    /**
     * One invoice for the line, billing every course it named.
     *
     * `base_amount` is the roll's own Original Price and `net_amount` is what
     * the institute actually agreed, so the difference lands in
     * `discount_amount` and the voucher reads coherently.
     *
     * The catalogue is deliberately NOT the base. It is today's price list, and
     * these are historical invoices: Super Kid Camp lists at 15,000 now and was
     * billed at 20,000 on 17 rows of the roll, which is a price change, not a
     * negative discount. Original Price is >= Discounted Price on all 478 rows,
     * so it is the one figure that makes `net <= base` true throughout.
     */
    private function challan(RollRow $row, Student $student): Challan
    {
        $base = $row->originalPrice;
        $net = $row->discountedPrice;
        $discount = max(0, $base - $net);

        // Enrolments first. `challans.admission_id` is NOT NULL — the column
        // predates grouped invoicing, when an invoice could only ever belong to
        // one — so the anchor has to exist before the invoice that names it.
        // `admissions.challan_id` is nullable in the other direction, because
        // enrolling without raising a fee is supported, and that asymmetry is
        // what makes this order the only one that works.
        $shares = $this->apportion($base, $row->courses);

        $admissions = [];
        foreach ($row->courses as $i => $course) {
            $cohort = $this->cohort($row, $course);

            $admission = Admission::create([
                'reg_no' => $this->sequences->nextAdmissionNo(),
                'student_id' => $student->id,
                'course_id' => $course->id,
                'cohort_id' => $cohort?->id,
                'enrolled_by' => $row->officer->id,
                // 'validated', the value the enum actually has. 'active' reads
                // like a synonym and is not one: the column is
                // enum('validated','pending','cancelled'), so MySQL refuses it
                // outright under strict mode, and where it does store — SQLite,
                // which lost the CHECK when an later migration rebuilt the
                // table — Course::seatsUsed() counts only 'validated', so every
                // imported student would be permanently invisible to capacity
                // while remaining visible to every money query.
                'status' => 'validated',
                'challan_id' => null,
                // This course's share of the gross, so one invoice covering
                // three courses can still answer "what did this one cost".
                'billed_amount' => $shares[$i],
                'import_key' => $row->importKeys[$course->id],
            ]);

            $this->backdate($admission, $row);
            $admissions[] = $admission;
        }

        $challan = Challan::create([
            'challan_no' => $this->sequences->nextChallanNo(),
            // The anchor is the first enrolment, for every caller that still
            // reads challans.admission_id rather than the admissions relation.
            'admission_id' => $admissions[0]->id,
            'base_amount' => $base,
            'discount_amount' => $discount,
            'discount_reason' => $discount > 0 ? 'Imported from the institute roll' : null,
            'discount_approved_by' => $discount > 0 ? $row->officer->id : null,
            'net_amount' => $net,
            'plan' => 'full',
            // The registration-date fallback applies only when nothing is owed,
            // where the date is cosmetic on an already-settled invoice. A row
            // that still owes money and names no deadline gets NULL, because
            // falling back here would date the debt to the day the student
            // enrolled and report it as months overdue on arrival.
            'due_date' => $row->secondDueOn ?? ($row->balance > 0 ? null : $row->registeredOn),
            'status' => 'unpaid',
        ]);

        foreach ($admissions as $admission) {
            $admission->update(['challan_id' => $challan->id]);
            $this->backdate($admission, $row);
        }

        $this->backdate($challan, $row);

        // The invoice's own trail. Skipping AppNotification for 478 rows was
        // right; skipping these looked like the same decision and was not — a
        // named discount approver with no record of the approval is exactly
        // what the audit log exists to prevent, and the challan drawer renders
        // this relation as the invoice's history.
        Audit::issued($challan, $row->officer, $this->when($row));

        if ($discount > 0) {
            Audit::discountApplied($challan, $row->officer, $this->when($row));
        }

        return $challan;
    }

    /**
     * Stamp a record with the day the thing actually happened.
     *
     * Not through `create()`: `created_at` is not in any of these models'
     * `$fillable`, so mass assignment drops it silently and Eloquent then
     * stamps `now()`. The first version of this importer passed it to
     * `Admission::create()` and looked correct while doing nothing at all.
     *
     * The enrolment and its invoice are ONE event and must land in the same
     * period. `Reporting::summary()` reads `billed` from `challans.created_at`
     * and `collected` from `payments.received_at`; dating one historically and
     * the other today puts two figures that must agree on the same screen a
     * whole roll apart, which is the defect class BUG-01 was.
     */
    private function backdate(Model $record, RollRow $row): void
    {
        // timestamps off around the save, or Eloquent overwrites `updated_at`
        // with now() on the way out and undoes half the point.
        $record->timestamps = false;
        $record->created_at = $row->registeredOn;
        $record->updated_at = $row->registeredOn;
        $record->save();
        $record->timestamps = true;
    }

    private function when(RollRow $row): Carbon
    {
        return Carbon::parse($row->registeredOn);
    }

    /**
     * Split the invoice's gross across its courses, in catalogue proportion.
     *
     * The shares have to sum to `base` exactly, because RevenueShare divides by
     * their total: if they came to less, every per-course revenue figure would
     * be scaled up by the shortfall. So the last course absorbs the rounding
     * rather than each share being rounded independently — the same reason
     * `Installments::defaultPlan()` computes its second part as `net - first`.
     *
     * Falls back to an even split when the catalogue prices sum to zero, which
     * a fully-scholarship course would otherwise turn into a division by zero.
     *
     * @param  list<Course>  $courses
     * @return list<int>
     */
    private function apportion(int $base, array $courses): array
    {
        $weights = array_map(fn ($c) => max(0, (int) $c->fee), $courses);
        $total = array_sum($weights);

        if ($total === 0) {
            $weights = array_fill(0, count($courses), 1);
            $total = count($courses);
        }

        $shares = [];
        $assigned = 0;
        foreach ($weights as $i => $w) {
            $last = $i === count($weights) - 1;
            $shares[$i] = $last ? $base - $assigned : (int) round($base * $w / $total);
            $assigned += $shares[$i];
        }

        return $shares;
    }

    private function cohort(RollRow $row, $course): ?Cohort
    {
        if ($row->batchText === '') {
            return null;
        }

        // Already created by ensureBatches(), outside any transaction.
        return $this->cohorts[$course->id.'|'.$row->batchName]
            ?? $row->cohorts[$course->id]
            ?? null;
    }

    /**
     * Record what was actually handed over — and only that.
     *
     * Driven by "Total Amount", never by "Advance + Second Installment". On a
     * Pending row the second instalment is the amount still owed, so adding it
     * here would book money nobody paid and mark the student settled, which
     * removes them from every list that would otherwise chase them.
     *
     * Written straight to the ledger rather than through ChallanActions, which
     * re-derives the balance and enforces the counter's rules. This is history
     * that already happened, and it has to land exactly as recorded even where
     * the modern rules would have refused it.
     */
    private function collect(RollRow $row, Challan $challan): void
    {
        if ($row->totalReceived <= 0) {
            return;
        }

        // The advance is the part that arrived on registration day. Where the
        // total exceeds it, the remainder was the instalment being settled, and
        // it is dated by the due date the roll gives for it.
        $advance = min($row->advance, $row->totalReceived);
        $later = $row->totalReceived - $advance;

        $this->payment($challan, $row, $advance, $row->registeredOn);

        if ($later > 0) {
            $this->payment($challan, $row, $later, $row->secondDueOn ?? $row->registeredOn);
        }

        if ($row->balance <= 0) {
            $challan->update([
                'status' => 'paid',
                'paid_via' => self::METHOD,
                'paid_at' => $row->secondDueOn ?? $row->registeredOn,
            ]);

            Audit::markedPaid($challan, $row->officer, self::METHOD, $this->when($row));
        }
    }

    private function payment(Challan $challan, RollRow $row, int $amount, ?string $on): void
    {
        if ($amount <= 0) {
            return;
        }

        Payment::create([
            'challan_id' => $challan->id,
            'amount' => $amount,
            'method' => self::METHOD,
            'received_by' => $row->officer->id,
            'received_at' => $on,
            'note' => 'Imported from the institute roll, line '.$row->line,
        ]);
    }

    /**
     * The production caller `Installments::schedule()` never had (GAP-03).
     *
     * Only where money is genuinely outstanding against a named instalment. A
     * student who simply has not finished paying has a balance, not a plan, and
     * inventing a schedule for them would put a deadline on the reports that
     * the institute never agreed with them.
     *
     * `reconcile()` then derives each part's status from the payments just
     * written, so the schedule cannot claim something the ledger does not
     * support.
     */
    private function schedule(RollRow $row, Challan $challan): void
    {
        if (! $row->needsSchedule()) {
            return;
        }

        // The plan is what was AGREED, so the first part is the advance the roll
        // names — not whatever happened to be collected. Deriving it from
        // receipts meant a Pending student who had paid nothing yet got no
        // schedule at all, and the deadline the institute actually set was
        // thrown away by the importer built to preserve it.
        $second = $row->balance;
        $first = $row->discountedPrice - $second;

        // schedule() refuses a plan that does not total the fee, and a refusal
        // here would roll back an otherwise good row.
        if ($second <= 0) {
            return;
        }

        // No deadline, so no schedule. `Installments::schedule()` orders parts
        // by date and its whole purpose is to answer "what falls due next"; a
        // part with no due date makes that question unanswerable, and passing
        // NULL would throw inside the transaction and lose the row.
        //
        // The balance is not lost by skipping this: it lives on the challan,
        // `Ledger::outstanding()` counts it, and `duesAgeing()` reports it as
        // Unscheduled. What it does not do is invent a deadline in order to have
        // one.
        if ($row->secondDueOn === null) {
            return;
        }

        // One part, not two, when nothing has been paid yet: the whole fee falls
        // due on the date the roll names. schedule() takes one or two parts, and
        // a zero-rupee first part is not a part.
        $parts = $first > 0
            ? [
                ['amount' => $first, 'due_date' => $row->registeredOn],
                ['amount' => $second, 'due_date' => $row->secondDueOn],
            ]
            : [['amount' => $second, 'due_date' => $row->secondDueOn]];

        $this->installments->schedule($challan, $parts);
    }
}
