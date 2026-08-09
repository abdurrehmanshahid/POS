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

    public function isRejected(): bool
    {
        return $this->reasons !== [];
    }

    /**
     * Ready to write: understood, and not already here.
     */
    public function isImportable(): bool
    {
        return ! $this->isRejected() && ! $this->alreadyImported;
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
