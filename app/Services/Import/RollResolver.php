<?php

namespace App\Services\Import;

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Student;
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

    /**
     * Every CNIC already spoken for, as `cnic => true`.
     *
     * Seeded from the database and added to as the file is walked, so one
     * lookup covers both "somebody already has this" and "an earlier line in
     * this same file claimed it". Loaded once for the whole run, like the
     * cohort and import-key sets above, rather than a query per row.
     */
    private array $cnics;

    public function __construct()
    {
        $this->courses = Course::all();
        $this->officers = User::all();
        $this->cnics = Student::query()
            ->whereNotNull('cnic')
            ->pluck('cnic')
            ->flip()
            ->all();

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

        $this->fingerprintCharges($rows);
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

            // Not a course, and no longer a refusal. These are the things the
            // institute sells that nobody enrols on, and they are billed as
            // charges — an invoice with a description and no admission. Kept as
            // the sheet's own words: there is no catalogue to resolve them
            // against, and inventing courses named "Co-working Space" would put
            // room bookings in the trainer's register and the capacity counts.
            if (in_array($needle, $this->notCourses, true)) {
                $row->charges[] = $part;

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

        // One line naming the same course twice is a typo rather than an
        // intention: a genuine second enrolment is a second line, with its own
        // money on it. (A student may hold two live enrolments on one course
        // since 2026_09_17_000001 — this rejection is about the spreadsheet,
        // not about that rule.)
        $codes = array_map(fn (Course $c) => $c->code, $row->courses);
        if (count($codes) !== count(array_unique($codes))) {
            $row->reject('the same course is named twice');
        }

        // One line, one kind. A course row becomes admissions carrying a
        // per-course share of the invoice; a charge row becomes an invoice with
        // no admission at all. A line naming both would have to be half of each,
        // and the apportionment has no share to give the charge — so it would
        // either swallow the charge's money into the courses or bill it twice.
        //
        // Refusing rather than guessing costs nothing here: no line in the
        // institute's roll mixes them, so this guards a case that does not
        // exist yet rather than one being papered over.
        if ($row->charges !== [] && $row->courses !== []) {
            $row->reject(
                'names a course and a charge on one line ("'
                .implode(', ', $row->charges).'"), which have to be billed separately'
            );
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

            // A group whose lines agree on WHO and on HOW MUCH is the same line
            // typed twice. The first is imported and the rest are collapsed, so
            // the export's own repetition does not cost the institute 19 rows
            // of history. 19 of the 21 groups in this roll are of this kind.
            if ($this->areTheSameClaim($sharing)) {
                foreach (array_slice($sharing, 1) as $repeat) {
                    $repeat->collapsed = true;
                }

                continue;
            }

            // Anything else is refused, and the reason now says what actually
            // disagrees rather than only that the lines collide.
            //
            // "Keep the line with the most money" was considered for these and
            // is NOT safe here, because they are usually not duplicates at all.
            // The fingerprint is (phone, course) and deliberately not the name,
            // so siblings on one course sharing a parent's number collide: lines
            // 200 and 206 of this roll are "Farah Atif" and "Riyan Bin Atif",
            // two people, and keeping the larger would have deleted a real
            // student along with the Rs 20,000 he had paid. Which of two
            // disagreeing claims is true is not the importer's call.
            foreach ($sharing as $row) {
                $row->reject(
                    "lines {$lines} claim the same student on the same course, and disagree: "
                    .$this->describeConflict($sharing)
                );
            }
        }
    }

    /**
     * Do these lines make one claim, or several?
     *
     * Compared on the person AND the money. Name alone would collapse two
     * siblings; money alone would collapse two different people who happened to
     * pay the same fee for the same course.
     *
     * @param  list<RollRow>  $rows
     */
    private function areTheSameClaim(array $rows): bool
    {
        $signature = fn (RollRow $r) => mb_strtolower(trim($r->name))
            .'|'.$r->discountedPrice.'|'.$r->totalReceived.'|'.$r->balance;

        return count(array_unique(array_map($signature, $rows))) === 1;
    }

    /**
     * Name the disagreement, so the operator can act on the rejection CSV
     * without opening the spreadsheet to work out what differs.
     *
     * @param  list<RollRow>  $rows
     */
    private function describeConflict(array $rows): string
    {
        $names = array_unique(array_map(fn (RollRow $r) => trim($r->name), $rows));

        if (count($names) > 1) {
            return 'different people ('.implode(' vs ', $names).') sharing one phone number';
        }

        $money = array_unique(array_map(
            fn (RollRow $r) => 'fee '.$r->discountedPrice.'/received '.$r->totalReceived,
            $rows
        ));

        return 'same person, different money ('.implode(' vs ', $money).')';
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
        // A phone that is present but unusable no longer stops the row either.
        // The column holds what can be dialled, so an undiallable value becomes
        // NULL — the same "we do not have a number" the 72 blank rows get —
        // and the row imports.
        //
        // These are two different faults wearing one message, and both end the
        // same way. Some are not numbers at all: ".", "--", "Digital Media",
        // "Shopify" — the course name typed into the wrong column. Others are
        // real attempts with the wrong digit count: "0316842216" is ten digits
        // where a PK mobile needs eleven, "032177634459" is twelve. A missing
        // digit cannot be guessed and an invented one would be worse than none.
        //
        // It is a WARNING rather than silence, because the typed text is the
        // only clue to what the number should have been. `--warnings=` writes
        // them out so the institute can chase them; discarding them quietly
        // would throw that away and nobody would know to look.
        if ($row->phone !== '' && ! Contact::normalizePhone($row->phone)) {
            $row->warn("phone \"{$row->phone}\" cannot be dialled, imported without a number");
        }

        $this->resolveCnic($row);

        if ($row->registeredOn === null) {
            $row->reject('no registration date');
        }
    }

    /**
     * Decide whether the sheet's CNIC is one this student can be stored with.
     *
     * The same doctrine as the phone above, and for the same reason: the person
     * matters more than the detail, so a number that cannot be used costs the
     * institute a warning rather than a student.
     *
     * Two ways it cannot be used, and both are real in the August intake:
     *
     *   - It is not a CNIC. "32102-292618-0" has twelve digits where a CNIC has
     *     thirteen, so somebody dropped one while typing. Which one cannot be
     *     recovered, and storing it anyway puts a number that identifies nobody
     *     into the column staff search by — worse than an empty cell, because an
     *     empty cell does not look like an answer.
     *   - It already belongs to somebody. `students.cnic` is UNIQUE, so writing
     *     it would abort the row inside its transaction and lose a real student
     *     and their fees over a duplicated identity number. Refused here so the
     *     operator gets a sentence instead of a raw SQLSTATE, and gets the
     *     student either way.
     *
     * Both the within-file and the already-in-the-database case are covered:
     * `$this->cnics` accumulates as the file is walked, so the second row to
     * claim a number keeps the student and drops the number, and the first —
     * already past this point — keeps both. Which of two claims is the real one
     * is not the importer's call, and the alternative is refusing both.
     */
    private function resolveCnic(RollRow $row): void
    {
        if ($row->cnic === '') {
            return;
        }

        if (! Contact::validCnic($row->cnic)) {
            $row->warn(
                "CNIC \"{$row->cnic}\" is not a valid CNIC (#####-#######-#), imported without one"
            );

            return;
        }

        if (isset($this->cnics[$row->cnic])) {
            $row->warn(
                "CNIC \"{$row->cnic}\" is already recorded against another student, imported without one"
            );

            return;
        }

        $this->cnics[$row->cnic] = true;
        $row->storedCnic = $row->cnic;
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
     * Hashed from the normalised phone, the NAME, and the course code.
     *
     * The name was deliberately excluded at first, and the reasoning was sound:
     * this importer is built around *correct a cell and run it again*, so every
     * component of the key has to survive an edit, and a name that gets
     * re-typed between exports produces a second student, a second invoice and
     * the same money booked twice.
     *
     * It was added anyway, because leaving it out was worse. (phone, course)
     * cannot tell two members of one family apart, and in this roll that is not
     * an edge case — 40 rows carrying Rs 670,000 are siblings sharing a parent's
     * number, three deep in places:
     *
     *     lines  10, 11, 12   Iram, Afsheen, Sofia Rajut
     *     lines 145, 146, 147 Huzaifa Amjad, Yahya Amjad, Zainab Tariq
     *
     * With the phone alone those collide, and the safe response to a collision
     * is to refuse both — so the strong key's price was 40 real students, and
     * their money, staying outside the system permanently. A key that cannot
     * represent a family is not strong, it is wrong.
     *
     * The cost is real and worth stating: correcting "Muhamad" to "Muhammad"
     * between two runs makes that row look new, and re-running would import it
     * twice. Three things bound that risk — the load is essentially one-time,
     * the name is lowercased and trimmed so casing and stray spaces do not
     * count as edits, and `php artisan records:duplicates` exists precisely to
     * find a pair that slips through.
     *
     * The registration date is still excluded, for the original reason: it is
     * re-typed as often as anything else and carries no identity.
     *
     * @param  Collection<string, int>  $existingKeys  flipped: key => position
     */
    private function fingerprint(RollRow $row, Collection $existingKeys): void
    {
        $person = Contact::normalizePhone($row->phone);

        if ($row->courses === []) {
            // Either rejected, or a charge — which is keyed by
            // {@see fingerprintCharges()} instead, because there is no course
            // and no admission to hang a key on.
            return;
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
        // Lowercased and trimmed, so "  Ali Raza" and "ali raza" are one person
        // and neither casing nor a stray space counts as an edit.
        $who = mb_strtolower(trim($row->name));

        $identity = $person !== null
            ? 'phone:'.$person.'|'.$who
            : 'name:'.$who.'|'.$row->registeredOn;

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

    /**
     * Two keys for a charge line, because it carries two identities.
     *
     * `personKey` is WHO, and it has to be blind to the date, or Azeem's six
     * monthly co-working bookings become six Azeems. `chargeKey` is WHAT
     * HAPPENED, and it has to be sharper than the person, or those same six
     * bookings collapse into one and Rs 75,000 of collections never lands.
     *
     * THE PERSON is keyed on the phone where there is one and the name where
     * there is not — the same order as {@see fingerprint()}. In this roll it is
     * always the name: not one of the 32 charge lines carries a dialable
     * number. Several carry the course typed into the phone column ("Shopify",
     * "Digital Media", "Summer kids") and one carries a ten-digit number where
     * a PK mobile needs eleven. So the weakest form of the key is the only form
     * available, and its two consequences are both visible in this data and
     * both handled by warning rather than guessing:
     *
     *   - "Azeem" and "M Azeem" are almost certainly one man renting one desk,
     *     and they import as two contacts. Merging them would mean deciding
     *     that "M" is an initial rather than a different person, which is the
     *     fuzzy match this importer refuses everywhere else.
     *   - "Abdullah IFtikhar" and "Noor fatima" appear BOTH as charge lines and
     *     as course students, and import as a contact beside their student
     *     record rather than onto it.
     *
     * Both are the safe direction: `php artisan records:duplicates` finds them
     * and a person can merge two records, whereas one record fusing two people
     * cannot be unpicked once money has landed on it.
     *
     * THE CHARGE is keyed on the person, the description and the date, plus an
     * occurrence index among lines identical in all three. The index exists for
     * Amna Imran and Fahad Ali, who each appear twice on 2025-07-16 for the same
     * Rs 7,500 recovery — byte-identical lines that are two real payments, so
     * no content-based hash can separate them and something outside the content
     * has to. The index is stable across re-runs precisely BECAUSE the lines are
     * identical: re-sorting the sheet cannot change which line gets #0 in any
     * way that matters, since the two are interchangeable.
     *
     * It is assigned to every charge line in file order including rejected ones.
     * Skipping the rejected would be the subtle bug here: fix a rejected line,
     * re-run, and it takes an index that shifts every later identical line onto
     * a fresh key — which re-imports money already in the ledger.
     *
     * The amount is deliberately NOT in the key. It is the field most likely to
     * be corrected between exports, and the occurrence index already separates
     * everything the amount would.
     *
     * @param  list<RollRow>  $rows
     */
    private function fingerprintCharges(array $rows): void
    {
        $charges = array_values(array_filter($rows, fn (RollRow $r) => $r->isCharge()));

        if ($charges === []) {
            return;
        }

        $alreadyHere = Challan::query()->whereNotNull('import_key')->pluck('import_key')->flip();

        // Names on the course lines of this same file, so a charge that shares
        // one can say so. Built from the sheet rather than the database because
        // the two may be imported in either order.
        $courseNames = [];
        foreach ($rows as $row) {
            if (! $row->isCharge() && $row->name !== '') {
                $courseNames[self::personName($row->name)] = $row->line;
            }
        }

        $occurrences = [];
        $firstSeenAt = [];

        foreach ($charges as $row) {
            $person = Contact::normalizePhone($row->phone);
            $who = self::personName($row->name);

            $identity = $person !== null ? 'phone:'.$person.'|'.$who : 'name:'.$who;
            $row->personKey = sha1('contact:'.$identity);

            $event = $identity.'|'.mb_strtolower($row->chargeDescription()).'|'.$row->registeredOn;
            $n = $occurrences[$event] = ($occurrences[$event] ?? -1) + 1;

            $row->chargeKey = sha1($event.'#'.$n);

            if ($alreadyHere->has($row->chargeKey)) {
                $row->alreadyImported = true;
            }

            // Say out loud what the weak key just decided, both ways round.
            // Silence here is what would let a merge that should not have
            // happened, or a split that should not have happened, pass for a
            // clean import.
            if (isset($firstSeenAt[$identity])) {
                $row->warn(
                    'billed to the same person as line '.$firstSeenAt[$identity]
                    .', matched on the name "'.trim($row->name).'" because no phone number is recorded'
                );
            } else {
                $firstSeenAt[$identity] = $row->line;
            }

            if (isset($courseNames[$who])) {
                $row->warn(
                    'line '.$courseNames[$who].' enrols a student of the same name; this charge is '
                    .'imported as a separate contact because there is no phone number to confirm '
                    .'they are one person. Merge them afterwards if they are.'
                );
            }
        }
    }

    /**
     * A name reduced to what two spellings of one person have in common.
     *
     * Lowercased and trimmed, exactly as {@see fingerprint()} has always done
     * it — shared so the two keys cannot drift into normalising differently,
     * and deliberately no more aggressive than the original, because widening
     * it would change every existing course key.
     */
    private static function personName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
