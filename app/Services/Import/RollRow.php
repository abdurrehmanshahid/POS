<?php

namespace App\Services\Import;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\User;

/**
 * One spreadsheet line, carried through the pipeline.
 *
 * Mutable on purpose: each stage fills in what it resolved and appends any
 * reason it could not, and the row arrives at the end knowing both what it
 * means and why it was refused. The alternative — a new immutable object per
 * stage — buys nothing here and makes the rejection report harder to assemble,
 * because a rejected row still has to remember everything resolved before the
 * stage that rejected it.
 */
class RollRow
{
    /** @var list<string> Why this row cannot be imported. Empty means it can. */
    public array $reasons = [];

    /** @var list<Course> Resolved, in the order the cell named them. */
    public array $courses = [];

    /**
     * Things this line bills that nobody enrols on.
     *
     * A co-working desk, a certificate reissue, a recovery settlement. Held as
     * the spreadsheet's own words rather than resolved against anything,
     * because there is nothing to resolve them against — that is what makes
     * them charges. 32 of the roll's 478 lines are these, Rs 289,950, and they
     * were refused outright until `challans` learned to bill without an
     * admission.
     *
     * @var list<string>
     */
    public array $charges = [];

    /** Identity of the person who bought the charge. @see RollResolver */
    public ?string $personKey = null;

    /** Identity of the charge itself, one per line. @see RollResolver */
    public ?string $chargeKey = null;

    /** @var array<int, Cohort> Resolved batch per course id; absent means create it. */
    public array $cohorts = [];

    /** Canonical batch name, computed once by the resolver. */
    public string $batchName = '';

    public ?User $officer = null;

    /** Already present in the database from an earlier run of this file. */
    public bool $alreadyImported = false;

    /** Stable content hash, one per (row, course). @var array<int, string> */
    public array $importKeys = [];

    public function __construct(
        public readonly int $line,
        public readonly string $name,
        public readonly string $courseText,
        public readonly string $status,
        public readonly string $csr,
        public readonly string $phone,
        public readonly string $batchText,
        public readonly ?string $registeredOn,
        public readonly ?string $secondDueOn,
        public readonly int $originalPrice,
        public readonly int $discountedPrice,
        public readonly int $advance,
        public readonly int $secondInstalment,
        public readonly int $balance,
        public readonly int $totalReceived,
    ) {}

    public function reject(string $reason): void
    {
        // De-duplicated: a four-course row that names the same unknown batch
        // four times should say so once.
        if (! in_array($reason, $this->reasons, true)) {
            $this->reasons[] = $reason;
        }
    }

    /**
     * Things worth telling the operator about a row that still imports.
     *
     * Distinct from `reasons`, which stop a row. A warning means the row loads
     * but something about it was lost or degraded on the way in — most often a
     * phone number that could not be dialled and is therefore stored as NULL.
     * Without this the loss would be silent, and "imported successfully" would
     * quietly mean "imported, minus a detail nobody mentioned".
     *
     * @var list<string>
     */
    public array $warnings = [];

    public function warn(string $warning): void
    {
        if (! in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    public function isRejected(): bool
    {
        return $this->reasons !== [];
    }

    /**
     * Does this line bill a service rather than teaching?
     *
     * A line is one or the other and never both — the resolver refuses a line
     * that names a course and a charge together, because the two take different
     * paths through the persister and half of each is not a thing that can be
     * written. No line in the institute's roll mixes them.
     */
    public function isCharge(): bool
    {
        return $this->charges !== [];
    }

    /** What the invoice says it is for, in the spreadsheet's own words. */
    public function chargeDescription(): string
    {
        return implode(', ', $this->charges);
    }

    /**
     * A byte-identical repeat of an earlier line in the same file.
     *
     * Not a rejection: the line is understood perfectly and its twin is being
     * imported. Counting it as refused would report 44 problems where there are
     * 19 harmless repetitions and hide the rows that do need a decision.
     */
    public bool $collapsed = false;

    /**
     * Ready to write: understood, not already here, and not a repeat of a line
     * this same run is already importing.
     */
    public function isImportable(): bool
    {
        return ! $this->isRejected() && ! $this->alreadyImported && ! $this->collapsed;
    }

    /**
     * Does this student owe a second, later payment?
     *
     * True only when money is genuinely outstanding AND the roll names an
     * instalment for it. That pairing is what separates a student on a plan
     * from one who simply has not finished paying.
     *
     * `balance` rather than a derived `discountedPrice - totalReceived`: the
     * validator refuses any row where those disagree, so the two are the same
     * number by the time anything reads this, and one name for it is enough.
     */
    public function needsSchedule(): bool
    {
        return $this->balance > 0 && $this->secondInstalment > 0;
    }
}
