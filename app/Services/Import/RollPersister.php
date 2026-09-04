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
        // Two shapes, one lifecycle. A charge skips the enrolment half entirely
        // — no admission, no batch, no apportionment, because there is no course
        // — and rejoins at the money, which is identical for both: the same
        // payments, the same schedule, the same audit trail.
        $student = $row->isCharge() ? $this->contact($row) : $this->student($row);
        $challan = $row->isCharge()
            ? $this->charge($row, $student)
            : $this->challan($row, $student);

        $this->collect($row, $challan);
        $this->schedule($row, $challan);

        Audit::record($row->isCharge() ? 'Charge imported' : 'Student imported', $row->officer, [
            'subject' => $student,
            'subject_label' => $student->student_code.' · '.$student->name,
            'field' => 'import',
            'new_value' => $challan->challan_no,
            'context' => [
                'line' => $row->line,
                'courses' => $row->isCharge()
                    ? $row->chargeDescription()
                    : implode(', ', array_map(fn ($c) => $c->code, $row->courses)),
                'received' => $row->totalReceived,
                'outstanding' => $row->balance,
            ],
        ]);
    }

    /**
     * The person who bought a service, found or created once.
     *
     * Keyed on `students.import_key`, which is what makes Azeem's six co-working
     * months one tenant with six invoices rather than six tenants with one each.
     * Each row commits its own transaction, so the second row genuinely finds
     * what the first wrote; there is no run-scoped memo to go stale.
     *
     * `withTrashed()` for the same reason `ensureBatches()` uses it: the unique
     * index counts soft-deleted rows, so a contact somebody removed would make
     * `create()` collide with a tombstone. Restoring is right — the institute is
     * re-importing the person it deleted.
     *
     * Created as `kind = 'contact'`, which keeps them out of the student counts
     * and every roster while leaving them fully present in the ledger. If they
     * ever enrol, `RegistrationService::register()` promotes them.
     */
    private function contact(RollRow $row): Student
    {
        $existing = Student::withTrashed()->where('import_key', $row->personKey)->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $this->joined($existing, $row);
        }

        return $this->joined(Student::create([
            // The same BBT-R.. series as everyone else, deliberately. A separate
            // series would read more honestly right up to the moment a contact
            // enrols, and then they would carry a code that says "not a student"
            // for the rest of their life — or be renumbered, invalidating every
            // document already printed with the old one.
            'student_code' => $this->sequences->nextStudentCode('R'),
            'type' => 'R',
            'kind' => 'contact',
            'name' => $row->name,
            'guardian_name' => null,
            'cnic' => null,
            'phone' => Contact::normalizePhone($row->phone),
            'created_by' => $row->officer->id,
            'import_key' => $row->personKey,
        ]), $row);
    }

    /**
     * An invoice for something nobody enrols on.
     *
     * Everything the enrolment path does about courses — batches, admissions,
     * apportioning the gross across them — is absent rather than skipped,
     * because none of it has a meaning here. What remains is the invoice
     * itself, and it is created with exactly the money rules
     * {@see Challan()} uses, through the same helper, so a charge and a fee
     * cannot come to disagree about what a discount is.
     */
    private function charge(RollRow $row, Student $student): Challan
    {
        return $this->raise($row, $student->id, null, [
            'description' => $row->chargeDescription(),
            // What makes a second run recognise this exact booking. Without it
            // re-running would bill Azeem's October desk again, and the ledger
            // would show money that never arrived.
            'import_key' => $row->chargeKey,
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
        // Somebody has looked at this line and said whose it is. That beats the
        // fingerprint, which by design cannot recognise a person across two
        // different courses and would otherwise mint them a second record.
        //
        // It does NOT skip the import key: the admission still gets its own, so
        // a second run recognises the enrolment and does not bill it twice.
        if ($row->attachTo) {
            return $this->joined($row->attachTo, $row);
        }

        $existing = Admission::query()
            ->whereIn('import_key', array_values($row->importKeys))
            ->with('student')
            ->first();

        if ($existing?->student) {
            return $this->joined($existing->student, $row);
        }

        return $this->joined(Student::create([
            'student_code' => $this->sequences->nextStudentCode('R'),
            'type' => 'R',
            'name' => $row->name,
            'guardian_name' => null,
            // What the RESOLVER decided to store, not what the sheet says. It
            // is NULL for a number that is not a CNIC or that already belongs
            // to somebody else, and NULL rather than '' because `students.cnic`
            // is UNIQUE and excludes NULLs while treating '' as a value two
            // students would collide on.
            'cnic' => $row->storedCnic,
            'phone' => Contact::normalizePhone($row->phone),
            'created_by' => $row->officer->id,
        ]), $row);
    }

    /**
     * Date the person by the day they actually joined, not the day of the import.
     *
     * The admission and the invoice have always been backdated — the same event
     * has to land in the same period, or `Reporting::summary()` reads billed and
     * collected a whole roll apart — and the person was quietly left out of it.
     * The result was 445 records whose drawer read "Joined 11 Aug 2026", the
     * afternoon the file was loaded, for students who walked in during July
     * 2025. The roll knows better on every row.
     *
     * PULLED BACK rather than simply set, because a person can appear on several
     * lines and the file is not in date order. Their record should start on the
     * earliest day they appear, so a later line cannot move the institute's
     * first sight of them forwards. Azeem's six co-working months come in
     * ascending order in this roll and would have been right either way; that is
     * luck, not a property of the export.
     */
    private function joined(Student $student, RollRow $row): Student
    {
        if ($row->registeredOn === null) {
            return $student;
        }

        $joined = Carbon::parse($row->registeredOn);

        if ($student->created_at !== null && $student->created_at->lte($joined)) {
            return $student;
        }

        // timestamps off, or Eloquent stamps `updated_at` with now() on the way
        // out — the same reason `backdate()` does it.
        $student->timestamps = false;
        $student->created_at = $joined;
        $student->updated_at = $joined;
        $student->save();
        $student->timestamps = true;

        return $student;
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

        $challan = $this->raise($row, $admissions[0]->student_id, $admissions[0]->id);

        foreach ($admissions as $admission) {
            $admission->update(['challan_id' => $challan->id]);
            $this->backdate($admission, $row);
        }

        return $challan;
    }

    /**
     * Write the invoice itself — the part a fee and a charge have in common.
     *
     * One implementation, because the two differ only in what they point at:
     * a fee names an anchor admission, a charge names a description and carries
     * its own import key. Everything else — how the roll's Original Price
     * becomes the base, how the gap to the agreed price becomes a discount with
     * a named approver, when a due date is left NULL, the backdating, the audit
     * trail — is the same fact stated once. Written twice, the second copy is
     * where the discount rule or the due-date fallback would quietly diverge.
     *
     * @param  array<string, mixed>  $extra
     */
    private function raise(RollRow $row, int $studentId, ?int $admissionId, array $extra = []): Challan
    {
        $base = $row->originalPrice;
        $net = $row->discountedPrice;
        $discount = max(0, $base - $net);

        $challan = Challan::create($extra + [
            'challan_no' => $this->sequences->nextChallanNo(),
            // The anchor is the first enrolment, for every caller that still
            // reads challans.admission_id rather than the admissions relation.
            // NULL for a charge, which is what `Challan::isCharge()` reads.
            'admission_id' => $admissionId,
            'student_id' => $studentId,
            'raised_by' => $row->officer->id,
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
