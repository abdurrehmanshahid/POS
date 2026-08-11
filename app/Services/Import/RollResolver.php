<?php

namespace App\Services\Import;

use App\Models\Admission;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\User;
use App\Support\Contact;
use Illuminate\Support\Collection;

/**
 * Stage 3 and 4: resolve every free-text reference, then prove the money.
 *
 * Deterministic throughout. Exact code, exact title, or an explicit alias from
 * `config/roll-import.php` — nothing else. Zero matches and two matches are
 * both refusals, because the cost of a wrong course is a student billed the
 * wrong fee and chased for money they do not owe, and the cost of a refusal is
 * one line added to a config file.
 */
class RollResolver
{
    /** @var Collection<int, Course> */
    private Collection $courses;

    /** @var Collection<int, User> */
    private Collection $officers;

    /** @var array<string, Cohort> keyed "courseId|lowercased name" */
    private array $cohortIndex;

    /** @var array<string, string> */
    private array $courseAliases;

    /** @var list<string> */
    private array $notCourses;

    /** @var array<string, string> */
    private array $csrUsernames;

    public function __construct()
    {
        $this->courses = Course::all();
        $this->officers = User::all();

        // Loaded once for the whole file, exactly as the import_key set below
        // is, and for the same reason: a per-row lookup here cost ~500 queries
        // on a 478-row sheet and ran even on a report-only pass.
        $this->cohortIndex = Cohort::all()
            ->keyBy(fn (Cohort $c) => $c->course_id.'|'.mb_strtolower($c->name))
            ->all();
        $this->courseAliases = array_change_key_case(config('roll-import.course_aliases', []));
        $this->notCourses = array_map('mb_strtolower', config('roll-import.not_courses', []));
        $this->csrUsernames = array_change_key_case(config('roll-import.csr_usernames', []));
    }

    /**
     * @param  list<RollRow>  $rows
     */
    public function resolve(array $rows): void
    {
        // One query for the whole file rather than one per row. At 478 rows the
        // per-row version was the difference between a dry-run you wait for and
        // one you do not.
        $existingKeys = Admission::query()
            ->whereNotNull('import_key')
            ->pluck('import_key')
            ->flip();

        foreach ($rows as $row) {
            $this->resolveCourses($row);
            $this->resolveOfficer($row);
            $this->resolveBatches($row);
            $this->validateIdentity($row);
            $this->validateMoney($row);
            $this->fingerprint($row, $existingKeys);
        }

        $this->rejectDuplicatesWithinTheFile($rows);
    }

    // ---- Stage 3: references ------------------------------------------------

    private function resolveCourses(RollRow $row): void
    {
        if ($row->courseText === '') {
            $row->reject('no course named');

            return;
        }

        // One cell can name several courses. They become several enrolments on
        // one invoice, which is what grouped invoicing was built for.
        foreach (array_map('trim', explode(',', $row->courseText)) as $part) {
            if ($part === '') {
                continue;
            }

            $needle = mb_strtolower($part);

            if (in_array($needle, $this->notCourses, true)) {
                $row->reject("not a course: \"{$part}\"");

                continue;
            }

            $matches = $this->courses->filter(fn (Course $c) => mb_strtolower($c->code) === $needle
                || mb_strtolower($c->title) === $needle
                || (isset($this->courseAliases[$needle])
                    && mb_strtolower($c->code) === mb_strtolower($this->courseAliases[$needle])));

            if ($matches->count() === 1) {
                $row->courses[] = $matches->first();

                continue;
            }

            $row->reject($matches->isEmpty()
                ? "unknown course: \"{$part}\""
                : "ambiguous course: \"{$part}\" matches ".$matches->count().' courses');
        }

        // The same course twice on one line cannot become two live enrolments —
        // the database forbids it, and it is a typo rather than an intention.
        $codes = array_map(fn (Course $c) => $c->code, $row->courses);
        if (count($codes) !== count(array_unique($codes))) {
            $row->reject('the same course is named twice');
        }
    }

    private function resolveOfficer(RollRow $row): void
    {
        if ($row->csr === '') {
            $row->reject('no CSR named');

            return;
        }

        $needle = mb_strtolower($row->csr);
        $username = $this->csrUsernames[$needle] ?? null;

        if ($username === null) {
            $row->reject("unknown CSR: \"{$row->csr}\"");

            return;
        }

        $user = $this->officers->firstWhere('username', $username);

        if (! $user) {
            $row->reject("CSR \"{$row->csr}\" maps to username \"{$username}\", which has no account");

            return;
        }

        $row->officer = $user;
    }

    /**
     * Batches are per-course here; the roll's batch is institute-wide.
     *
     * So the batch is resolved inside each course the row already resolved. A
     * name that exists on another course is not this course's batch, and saying
     * so is the point of the check.
     */
    private function resolveBatches(RollRow $row): void
    {
        if ($row->batchText === '') {
            return; // Unbatched is legitimate; the batches screen can place them later.
        }

        $name = self::canonicalBatch($row->batchText);

        $row->batchName = $name;

        foreach ($row->courses as $course) {
            $cohort = $this->cohortIndex[$course->id.'|'.mb_strtolower($name)] ?? null;

            // Absent is fine: the persister creates it. Only resolved cohorts
            // are stored, so "absent" and "present but null" cannot diverge.
            if ($cohort) {
                $row->cohorts[$course->id] = $cohort;
            }
        }
    }

    /**
     * "Batch 7", "Batch # 10", "Batch #09", "Batch # 04" are one naming scheme
     * written four ways. Fold them so the import does not create four batches
     * where the institute has one.
     */
    public static function canonicalBatch(string $raw): string
    {
        $aliases = array_change_key_case(config('roll-import.batch_aliases', []));
        $needle = mb_strtolower($raw);

        if (isset($aliases[$needle])) {
            return $aliases[$needle];
        }

        if (preg_match('/^batch\s*#?\s*0*(\d+)$/i', $raw, $m)) {
            return 'Batch '.(int) $m[1];
        }

        return $raw;
    }

    /**
     * Two lines in one file claiming the same (phone, course).
     *
     * `fingerprint()` only compares against what is already in the database, so
     * without this the first line imports, the second hits the UNIQUE index
     * inside its transaction, and the operator gets a raw
     * `SQLSTATE[23000] UNIQUE constraint failed` instead of a sentence. Real in
     * this roll: 52 phone numbers are shared by more than one student, mostly
     * siblings on a parent's number, so two of them on one course collide.
     *
     * Both lines are refused, not just the second. Which of two identical
     * claims is the real one is not the importer's call.
     *
     * @param  list<RollRow>  $rows
     */
    private function rejectDuplicatesWithinTheFile(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            foreach ($row->importKeys as $key) {
                $seen[$key][] = $row;
            }
        }

        foreach ($seen as $sharing) {
            if (count($sharing) < 2) {
                continue;
            }

            $lines = implode(', ', array_map(fn (RollRow $r) => $r->line, $sharing));
            foreach ($sharing as $row) {
                $row->reject("lines {$lines} claim the same student on the same course");
            }
        }
    }

    // ---- Stage 4: identity and money ---------------------------------------

    private function validateIdentity(RollRow $row): void
    {
        if ($row->name === '') {
            $row->reject('no student name');
        }

        // A missing phone is no longer a rejection. `students.phone` became
        // nullable so the 72 rows of legacy history that carry "-" here can be
        // loaded as what they are — students whose number nobody recorded —
        // rather than dropped or given an invented one.
        //
        // A phone that is PRESENT and malformed is still refused. "." and
        // "Digital Media" are not numbers nobody has, they are numbers somebody
        // typed wrongly, and storing them would put a value in the column that
        // no reminder can ever reach while looking as though it can.
        if ($row->phone !== '' && ! Contact::normalizePhone($row->phone)) {
            $row->reject("phone is not a PK mobile: \"{$row->phone}\"");
        }

        if ($row->registeredOn === null) {
            $row->reject('no registration date');
        }
    }

    /**
     * Prove the row's arithmetic before anything is written.
     *
     * The invariant is `discounted price = total received + balance`, and it is
     * checked against the roll's OWN Balance column rather than derived from
     * it, so a row whose figures disagree is refused instead of quietly
     * rebalanced to whatever the importer preferred.
     *
     * The distinction that matters most is what counts as received. "Total
     * Amount" (P) is the only column that means money in hand:
     *
     *   - On all 33 Pending rows, "Second Installment" equals the Balance
     *     exactly and "Total Amount" equals the Advance alone. There, the
     *     second instalment is SCHEDULED and unpaid.
     *   - On the 36 settled rows that carry one, it was collected, and
     *     "Total Amount" includes it.
     *
     * One column, two meanings, told apart only by P. Deriving payments from
     * "Advance + Second Installment" would have invented Rs 450,688 of revenue
     * across those 33 students, and — worse than the number — it would have
     * marked them settled, so nobody would ever have chased the real balance.
     */
    private function validateMoney(RollRow $row): void
    {
        if ($row->discountedPrice <= 0) {
            $row->reject('no discounted price, so there is no fee to bill');

            return;
        }

        if ($row->totalReceived < 0 || $row->advance < 0 || $row->balance < 0) {
            $row->reject('negative money');

            return;
        }

        if ($row->totalReceived > $row->discountedPrice) {
            $row->reject("collected {$row->totalReceived} against a fee of {$row->discountedPrice}");

            return;
        }

        // net must not exceed base. `RegistrationService` says the same thing as
        // "discount is 0-100%", and Challan's docblock promises net is derived
        // from base and never accepted from a client — but this importer does
        // accept it, from a spreadsheet, so the bound has to be restated here
        // or the voucher can print Total 25,000 / Discount 0% / Net Payable
        // 90,000, which is an incoherent document to hand a parent.
        //
        // Measured against the roll's OWN Original Price, not today's catalogue.
        // A 2025 invoice's base is what the institute billed in 2025; the
        // catalogue has moved since, and comparing against it rejected 34 rows
        // whose only fault was that Super Kid Camp used to cost more. Original
        // Price is >= Discounted Price in all 478 rows, so it is a base the data
        // actually supports.
        if ($row->originalPrice < $row->discountedPrice) {
            $row->reject(
                "fee {$row->discountedPrice} is more than the original price {$row->originalPrice}"
            );

            return;
        }

        if ($row->discountedPrice !== $row->totalReceived + $row->balance) {
            $row->reject(
                "money does not reconcile: fee {$row->discountedPrice} "
                ."≠ received {$row->totalReceived} + balance {$row->balance}"
            );

            return;
        }

        // Status is redundant with the balance in every one of the 478 rows, so
        // it is a free cross-check on the reading rather than a second source
        // of truth. A disagreement means the row was misread.
        $settled = mb_strtolower($row->status) === 'paid';
        if ($settled && $row->balance !== 0) {
            $row->reject("status says Paid but a balance of {$row->balance} remains");
        }
        if (! $settled && $row->balance === 0 && $row->status !== '') {
            $row->reject("status says {$row->status} but nothing is outstanding");
        }

        // An outstanding balance with no due date is no longer a rejection. The
        // balance is imported UNSCHEDULED: the challan carries a NULL due date,
        // no installment plan is created, and `Reporting::duesAgeing()` reports
        // it under "Unscheduled".
        //
        // The rejected alternative was to fall back to the registration date, as
        // this importer does for dated rows. That would have made all 33 of
        // these balances overdue by months the instant they landed, and the
        // institute would have begun chasing parents over a deadline nobody ever
        // set. Owing money on no particular date is the truth here, and it is
        // representable.

        // `Installments::schedule()` refuses parts that fall due out of order,
        // and it refuses them at write time — so without this the dry run calls
        // the row ready and `--commit` then throws on it, which defeats the one
        // promise the dry run makes.
        if ($row->needsSchedule() && $row->secondDueOn !== null && $row->registeredOn !== null
            && $row->secondDueOn < $row->registeredOn) {
            $row->reject(
                "the instalment falls due {$row->secondDueOn}, before the registration on {$row->registeredOn}"
            );
        }
    }

    /**
     * A stable fingerprint, one per (row, course).
     *
     * Not the spreadsheet's own ID column: that is a 1..478 sequence produced by
     * the export, so it renumbers the moment anyone filters or re-sorts before
     * exporting again, and two different files would claim the same identity.
     *
     * Hashed from the normalised phone and the course code, and deliberately
     * NOT from the name or the registration date. The whole workflow this
     * importer is built around is *correct a cell and run it again*, so every
     * component of the key has to survive that. An earlier version included
     * both: fixing one typo'd digit in a phone, or a misspelled name, produced
     * a second student, a second invoice and the same money booked twice.
     *
     * Phone rather than name because the roll has 59 names shared by more than
     * one row and no CNIC to tell them apart, while `normalizePhone` gives one
     * canonical form for every way a number can be written — and a row whose
     * phone will not normalise has already been rejected before this runs.
     *
     * @param  Collection<string, int>  $existingKeys  flipped: key => position
     */
    private function fingerprint(RollRow $row, Collection $existingKeys): void
    {
        $person = Contact::normalizePhone($row->phone);

        if ($row->courses === []) {
            return; // Already rejected; there is nothing stable to key on.
        }

        // A phoneless row still needs an identity, or it could not be imported
        // at all: without a key it can be neither recognised on a second run nor
        // caught by the within-file duplicate check, so re-running the importer
        // would load all 72 of them again as new people.
        //
        // The fallback is the name and the registration date, and it is
        // deliberately NAMESPACED away from the phone key. Without the prefix a
        // phoneless row and a phoned row could in principle hash to the same
        // value and one would silently claim the other had already been
        // imported.
        //
        // The fallback is weaker than the phone, and it is worth being honest
        // about how: the roll has 59 names shared by more than one row, so two
        // different people with the same name enrolling on the same course on
        // the same day collide — the within-file duplicate check then refuses
        // both, which is the safe direction to fail. And unlike a phone, a name
        // gets re-typed between exports, so fixing a spelling mistake makes the
        // row look new. Both are acceptable for 72 rows of dead history; neither
        // would be acceptable as the primary key for the whole file, which is
        // why the phone is still used wherever there is one.
        $identity = $person !== null
            ? 'phone:'.$person
            : 'name:'.mb_strtolower(trim($row->name)).'|'.$row->registeredOn;

        foreach ($row->courses as $course) {
            $row->importKeys[$course->id] = sha1($identity.'|'.$course->code);
        }

        $matched = array_filter($row->importKeys, fn ($k) => $existingKeys->has($k));

        // All of them: this line is done, skip it silently.
        // None: new work.
        // SOME: the line has grown since it was imported — a course was added,
        // or one line was split into two. Re-running cannot complete it,
        // because the row-level skip would pass over the new courses forever
        // and the invoice would stay at its old, lower net. Refusing loudly
        // puts it on the operator's decision list instead of freezing it
        // silently under "already imported". Merging onto a paid invoice means
        // re-apportioning money already collected, which is a person's call.
        if (count($matched) === count($row->importKeys)) {
            $row->alreadyImported = true;
        } elseif ($matched !== []) {
            $new = array_diff_key($row->importKeys, $matched);
            $codes = array_map(
                fn ($id) => $this->courses->firstWhere('id', $id)?->code ?? $id,
                array_keys($new)
            );
            $row->reject('already imported, but '.implode(', ', $codes)
                .' on this line is new and re-running cannot add it');
        }
    }
}
