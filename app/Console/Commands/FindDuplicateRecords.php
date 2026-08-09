<?php

namespace App\Console\Commands;

use App\Models\Admission;
use App\Models\Payment;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Find records a double-submit may already have created (GAP-08).
 *
 * The guard added in this change stops new ones. It cannot know about rows
 * written before it existed, and nothing in the database flags them: a phantom
 * payment reduces the outstanding balance by exactly the amount it invents, so
 * `billed = received + outstanding` still holds and every report stays
 * internally consistent. Only the timing gives it away.
 *
 * REPORTS ONLY. There is no `--fix`, and that is deliberate. Deleting a
 * collected payment is exactly what the rest of this system refuses to do —
 * `payments` is an append-only ledger and `ChallanActions::cancel()` will not
 * touch a challan that has money against it. A row that turns out to be
 * phantom is corrected by an offsetting reversal, which is GAP-07's job, with a
 * reason and an audit trail. A command that quietly deleted money rows would be
 * a worse bug than the one it was cleaning up after.
 */
class FindDuplicateRecords extends Command
{
    protected $signature = 'records:duplicates
        {--seconds=15 : How close together two records have to be to look like one double-click}';

    protected $description = 'Report records that a double-submitted form may have created';

    public function handle(): int
    {
        $window = max(1, (int) $this->option('seconds'));

        $this->components->info("Looking for records written within {$window}s of an identical one.");

        $found = $this->payments($window)
            + $this->students($window)
            + $this->admissions($window);

        $this->newLine();

        if ($found === 0) {
            $this->components->info('Nothing found.');

            return self::SUCCESS;
        }

        $this->components->warn("{$found} suspected duplicate(s).");
        $this->line('  These are <options=bold>candidates, not verdicts</> — a student really can pay the');
        $this->line('  same amount twice in a minute. Check each against what was actually');
        $this->line('  collected before treating it as an error.');
        $this->newLine();
        $this->line('  Nothing is deleted. Correcting a payment means recording a reversal');
        $this->line('  against it (GAP-07), never removing the row.');

        return self::SUCCESS;
    }

    /**
     * Same challan, same amount, same method, seconds apart.
     *
     * Ordered by the signature columns FIRST and time second, which is what
     * lets `clusters()` keep one rolling row instead of a map of every
     * signature it has ever seen. Ordering by time alone would work too, but
     * only by holding the whole table's worth of keys in memory.
     */
    private function payments(int $window): int
    {
        $rows = Payment::query()
            ->orderBy('challan_id')->orderBy('amount')->orderBy('method')->orderBy('received_at')
            ->select(['id', 'challan_id', 'amount', 'method', 'received_at'])
            ->cursor();

        $suspects = $this->clusters(
            $rows,
            fn ($p) => $p->challan_id.'|'.$p->amount.'|'.$p->method,
            'received_at',
            $window
        );

        return $this->report('Payments', $suspects, fn ($p) => [
            $p->id,
            'challan '.$p->challan_id,
            $p->amount,
            $p->method,
            (string) $p->received_at,
        ], ['id', 'challan', 'amount', 'method', 'received at']);
    }

    /**
     * Same name and phone, created seconds apart.
     *
     * Ordered by the SAME expression the signature uses, not by the raw column.
     * `ORDER BY name` with a `lower(trim(name))` signature puts "Bob" and "bob"
     * in different places under SQLite's binary collation, so the pair the
     * signature is meant to catch is never adjacent and never found.
     */
    private function students(int $window): int
    {
        $rows = Student::query()
            ->orderByRaw('lower(trim(name))')->orderBy('phone')->orderBy('created_at')
            ->select(['id', 'student_code', 'name', 'phone', 'created_at'])
            ->cursor();

        $suspects = $this->clusters(
            $rows,
            fn ($s) => mb_strtolower(trim($s->name)).'|'.$s->phone,
            'created_at',
            $window
        );

        return $this->report('Students', $suspects, fn ($s) => [
            $s->id, $s->student_code, $s->name, $s->phone, (string) $s->created_at,
        ], ['id', 'code', 'name', 'phone', 'created at']);
    }

    /**
     * Same student on the same course, seconds apart.
     *
     * The unique live-enrolment index (BUG-17) already stops this while both
     * are live, so anything here means one of them was cancelled afterwards —
     * worth a look, but far less likely than the other two.
     */
    private function admissions(int $window): int
    {
        $rows = Admission::query()
            ->orderBy('student_id')->orderBy('course_id')->orderBy('created_at')
            ->select(['id', 'reg_no', 'student_id', 'course_id', 'status', 'created_at'])
            ->cursor();

        $suspects = $this->clusters(
            $rows,
            fn ($a) => $a->student_id.'|'.$a->course_id,
            'created_at',
            $window
        );

        return $this->report('Admissions', $suspects, fn ($a) => [
            $a->id, $a->reg_no, 'student '.$a->student_id, 'course '.$a->course_id, $a->status, (string) $a->created_at,
        ], ['id', 'reg no', 'student', 'course', 'status', 'created at']);
    }

    /**
     * Rows sharing a signature and landing within `$window` seconds of the one
     * before them.
     *
     * Chained rather than compared to the first of the group: three clicks
     * produce three rows a few seconds apart each, and anchoring on the first
     * would miss the third if the officer was slow.
     *
     * One rolling `$previous`, not a map of every signature seen. That is only
     * safe because each caller sorts by its signature columns before time, so
     * rows that share a signature are adjacent — which is also what lets the
     * callers stream with `cursor()` instead of loading the table.
     *
     * @param  iterable<mixed>  $rows  in (signature…, time) order
     * @param  string  $timeColumn  the attribute holding the row's timestamp
     * @return Collection<int, mixed>
     */
    private function clusters(iterable $rows, callable $signature, string $timeColumn, int $window): Collection
    {
        $previous = null;
        $suspects = collect();

        foreach ($rows as $row) {
            $key = $signature($row);
            $when = $row->{$timeColumn};

            if ($when === null) {
                continue;
            }

            // `diffInSeconds` is SIGNED in Carbon 3, so a row that arrives out
            // of order gives a negative gap and `-7200 <= 15` is perfectly
            // true. The ordering below should make that impossible; the lower
            // bound is here so that a future change to a caller's ORDER BY
            // produces no result rather than a page of false positives.
            $gap = $previous === null ? null : $previous['at']->diffInSeconds($when);

            $isRepeat = $previous !== null
                && $previous['key'] === $key
                && $gap >= 0 && $gap <= $window;

            if (! $isRepeat) {
                $previous = ['key' => $key, 'at' => $when, 'row' => $row, 'reported' => false];

                continue;
            }

            // Include the row that started the run, once, so the report shows
            // the pair rather than an orphaned second half.
            if (! $previous['reported']) {
                $suspects->push($previous['row']);
                $previous['reported'] = true;
            }
            $suspects->push($row);
            $previous['at'] = $when;
        }

        return $suspects;
    }

    /**
     * @param  Collection<int, mixed>  $suspects
     * @param  list<string>  $headers
     */
    private function report(string $label, Collection $suspects, callable $row, array $headers): int
    {
        if ($suspects->isEmpty()) {
            $this->components->twoColumnDetail($label, '<fg=green>none</>');

            return 0;
        }

        $this->newLine();
        $this->components->twoColumnDetail("<options=bold>{$label}</>", '<fg=yellow>'.$suspects->count().' suspect</>');
        $this->table($headers, $suspects->map($row)->all());

        return $suspects->count();
    }
}
