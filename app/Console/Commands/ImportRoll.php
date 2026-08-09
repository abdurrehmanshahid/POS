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
        {--rejects= : Write a CSV of every rejected row to this path}';

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

        $this->summary($ready, $already, $rejected);
        $this->unresolved($rejected);

        if ($path = $this->option('rejects')) {
            $this->writeRejects($rejected, (string) $path);
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

    private function summary(array $ready, array $already, array $rejected): void
    {
        $money = array_sum(array_map(fn (RollRow $r) => $r->totalReceived, $ready));
        $owed = array_sum(array_map(fn (RollRow $r) => $r->balance, $ready));

        $this->newLine();
        $this->table(['', 'Rows'], [
            ['Ready to import', count($ready)],
            ['Already imported', count($already)],
            ['Rejected', count($rejected)],
            ['Total', count($ready) + count($already) + count($rejected)],
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
