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
        {--warnings= : Write a CSV of every row that imported with something lost}
        {--hold= : Refuse the roll lines named in this file. See docs/QUARANTINE-REGISTER.md}';

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

        if ($path = $this->option('hold')) {
            try {
                $held = $this->hold($rows, (string) $path);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->info($held.' row(s) held by '.basename((string) $path).'.');
        }

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

    /**
     * Refuse the roll lines named in a hold file (the quarantine register).
     *
     * Held rows are rejected rather than skipped, so they travel the same path
     * as every other refusal: they appear in the summary, in `--rejects`, and in
     * the grouped "what needs a decision" list. A row that vanished silently
     * would make `478 = ready + already + rejected` stop adding up, and the
     * arithmetic in that table is the only thing telling an operator that no row
     * was quietly lost.
     *
     * Applied BEFORE `resolve()` on purpose. A held row must be refused for
     * being held, not for whichever unrelated thing the resolver would have
     * complained about first — otherwise releasing it from quarantine surfaces a
     * second reason nobody knew was there.
     *
     * The reason from the file's own comment is carried into the rejection, so
     * the report says *why* a line is held rather than only that it is.
     *
     * @param  list<RollRow>  $rows
     */
    private function hold(array $rows, string $path): int
    {
        if (! is_readable($path)) {
            throw new \RuntimeException("Hold file not readable: {$path}");
        }

        $reasons = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $no => $text) {
            // Everything after a '#' is a comment, and a line that is only a
            // comment is how the file documents itself.
            [$line, $why] = array_pad(explode('#', $text, 2), 2, '');
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! ctype_digit($line)) {
                throw new \RuntimeException(
                    basename($path)." line {$no}: expected a roll line number, got \"{$line}\""
                );
            }

            $reasons[(int) $line] = trim($why);
        }

        $held = 0;

        foreach ($rows as $row) {
            if (! array_key_exists($row->line, $reasons)) {
                continue;
            }

            $why = $reasons[$row->line];
            $row->reject('held in quarantine'.($why !== '' ? ": {$why}" : ''));
            $held++;
        }

        // A number in the file matching no row means the file and the roll have
        // drifted apart — a re-export with different line numbering would hold
        // the wrong students, silently. Louder than a comment, cheaper than the
        // audit that finds it later.
        $matched = array_map(fn (RollRow $r) => $r->line, $rows);
        if ($missing = array_diff(array_keys($reasons), $matched)) {
            $this->components->warn(
                count($missing).' held line(s) match no row in this file: '
                .implode(', ', array_slice($missing, 0, 10)).(count($missing) > 10 ? ' …' : '')
            );
        }

        return $held;
    }

    private function summary(array $ready, array $already, array $rejected, array $collapsed = []): void
    {
        $money = array_sum(array_map(fn (RollRow $r) => $r->totalReceived, $ready));
        $owed = array_sum(array_map(fn (RollRow $r) => $r->balance, $ready));

        // Called out rather than folded into the total, because these rows
        // create a contact and an invoice with no admission — no enrolment, no
        // seat, nothing in a register. An operator reading "413 imported" would
        // otherwise reasonably read all 413 as students.
        $charges = array_filter($ready, fn (RollRow $r) => $r->isCharge());

        $this->newLine();
        $this->table(['', 'Rows'], [
            ['Ready to import', count($ready)],
            ['  of which are charges, not enrolments', count($charges)],
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
