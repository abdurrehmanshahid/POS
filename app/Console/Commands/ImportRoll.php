<?php

namespace App\Console\Commands;

use App\Services\Import\RollPersister;
use App\Services\Import\RollReader;
use App\Services\Import\RollResolver;
use App\Services\Import\RollRow;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import the institute's existing roll (GAP-04).
 *
 * Reports by default and writes only when told to. `--dry-run` is not a flag
 * you remember to add; `--commit` is a flag you have to mean, so forgetting one
 * produces a report rather than 478 students, several hundred invoices and a
 * payment history that has to be unpicked by hand.
 */
class ImportRoll extends Command
{
    protected $signature = 'roll:import
        {file : Path to the .xlsx exported from the institute system}
        {--commit : Actually write. Without this the command only reports.}
        {--rejects= : Write a CSV of every rejected row to this path}
        {--warnings= : Write a CSV of every row that imported with something lost}';

    protected $description = 'Import students, enrolments, invoices and payment history from an exported roll';

    public function handle(RollReader $reader, RollResolver $resolver, RollPersister $persister): int
    {
        $file = (string) $this->argument('file');

        try {
            $rows = $reader->read($file);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Read '.count($rows).' rows from '.basename($file));

        $resolver->resolve($rows);

        $ready = array_filter($rows, fn (RollRow $r) => $r->isImportable());
        $already = array_filter($rows, fn (RollRow $r) => ! $r->isRejected() && $r->alreadyImported);
        $rejected = array_filter($rows, fn (RollRow $r) => $r->isRejected());
        // Identical repeats of a line this same run is importing. Reported
        // separately because they are neither a problem nor work to do.
        $collapsed = array_filter($rows, fn (RollRow $r) => ! $r->isRejected() && $r->collapsed);

        $this->summary($ready, $already, $rejected, $collapsed);
        $this->unresolved($rejected);

        $this->caveats($rows);

        if ($path = $this->option('rejects')) {
            $this->writeRejects($rejected, (string) $path);
        }

        if ($path = $this->option('warnings')) {
            $this->writeWarnings($rows, (string) $path);
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->components->warn('Dry run. Nothing was written. Re-run with --commit to import.');

            return self::SUCCESS;
        }

        if ($ready === []) {
            $this->components->warn('Nothing to import.');

            return self::SUCCESS;
        }

        $this->newLine();
        $result = $persister->persist($rows);

        $this->components->info($result['imported'].' rows imported.');

        foreach ($result['failed'] as $f) {
            $this->components->error("line {$f['line']}: {$f['error']}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function summary(array $ready, array $already, array $rejected, array $collapsed = []): void
    {
        $money = array_sum(array_map(fn (RollRow $r) => $r->totalReceived, $ready));
        $owed = array_sum(array_map(fn (RollRow $r) => $r->balance, $ready));

        $this->newLine();
        $this->table(['', 'Rows'], [
            ['Ready to import', count($ready)],
            ['Already imported', count($already)],
            ['Duplicate lines collapsed', count($collapsed)],
            ['Rejected', count($rejected)],
            ['Total', count($ready) + count($already) + count($rejected) + count($collapsed)],
        ]);

        $this->line("  Money in the importable rows:  received <fg=green>{$money}</> · outstanding <fg=yellow>{$owed}</>");
    }

    /**
     * The values that need a decision, grouped and counted.
     *
     * This is the section the operator acts on. A list of 300 rejected lines is
     * unreadable; "unknown course: DevOps (12 rows)" is one course to create,
     * and the same list run again is how the file converges.
     */
    private function unresolved(array $rejected): void
    {
        $groups = [];
        foreach ($rejected as $row) {
            foreach ($row->reasons as $reason) {
                $groups[$reason] = ($groups[$reason] ?? 0) + 1;
            }
        }

        if ($groups === []) {
            return;
        }

        arsort($groups);

        $this->newLine();
        $this->components->info('What needs a decision, most common first');
        foreach (array_slice($groups, 0, 40, true) as $reason => $count) {
            $this->line(sprintf('  <fg=yellow>%4d</>  %s', $count, $reason));
        }

        if (count($groups) > 40) {
            $this->line('  … and '.(count($groups) - 40).' more; the CSV has all of them.');
        }
    }

    /**
     * Rows that DO import, but with something lost on the way in.
     *
     * Printed separately from the rejection list and never folded into it: a
     * rejection is work to do before the import, a caveat is work to do after
     * it, and reporting them together would bury the second under the first.
     *
     * @param  list<RollRow>  $rows
     */
    private function caveats(array $rows): void
    {
        $groups = [];
        foreach ($rows as $row) {
            if (! $row->isImportable()) {
                continue;
            }
            foreach ($row->warnings as $warning) {
                // Grouped by kind, not by value: 25 different unusable numbers
                // are one thing to fix, not 25 lines to read.
                $kind = preg_replace('/"[^"]*"/', '…', $warning);
                $groups[$kind] = ($groups[$kind] ?? 0) + 1;
            }
        }

        if ($groups === []) {
            return;
        }

        arsort($groups);

        $this->newLine();
        $this->components->warn('Imported, but with something lost — pass --warnings= for the list');
        foreach ($groups as $kind => $count) {
            $this->line(sprintf('  <fg=yellow>%4d</>  %s', $count, $kind));
        }
    }

    /**
     * The rows above, in full, so the detail can be chased.
     *
     * Carries the ORIGINAL text that could not be used — an unusable phone is
     * the only surviving clue to what the real number was, and the point of
     * this file is that the clue is not thrown away.
     *
     * @param  list<RollRow>  $rows
     */
    private function writeWarnings(array $rows, string $path): void
    {
        $out = @fopen($path, 'w');

        if ($out === false) {
            $this->components->error('Could not write the warnings CSV to '.$path.'; continuing.');

            return;
        }

        fputcsv($out, ['line', 'name', 'course', 'csr', 'phone_as_written', 'warnings']);

        $written = 0;
        foreach ($rows as $row) {
            if (! $row->isImportable() || $row->warnings === []) {
                continue;
            }

            fputcsv($out, [
                $row->line, $row->name, $row->courseText, $row->csr, $row->phone,
                implode('; ', $row->warnings),
            ]);
            $written++;
        }

        fclose($out);
        $this->components->info($written.' rows with caveats written to '.$path);
    }

    /**
     * Machine-readable rejections, so the roll can be corrected and re-run
     * rather than forty rows being retyped out of terminal scrollback.
     */
    private function writeRejects(array $rejected, string $path): void
    {
        $out = @fopen($path, 'w');

        // Reported, never thrown. This runs before the commit block, so an
        // unwritable path used to abort the whole import with a TypeError from
        // fputcsv(false, ...) — a diagnostic file failing to open must not stop
        // the thing it was diagnosing.
        if ($out === false) {
            $this->components->error('Could not write the rejects CSV to '.$path.'; continuing.');

            return;
        }

        fputcsv($out, ['line', 'name', 'course', 'csr', 'batch', 'phone', 'discounted', 'received', 'balance', 'reasons']);

        foreach ($rejected as $row) {
            fputcsv($out, [
                $row->line, $row->name, $row->courseText, $row->csr, $row->batchText, $row->phone,
                $row->discountedPrice, $row->totalReceived, $row->balance,
                implode('; ', $row->reasons),
            ]);
        }

        fclose($out);
        $this->newLine();
        $this->components->info(count($rejected).' rejected rows written to '.$path);
    }
}
