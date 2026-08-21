<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Restore the newest dump into a scratch database and prove it came back.
 *
 * B-05 was never "there is no backup" — the super admin could always download
 * one. It was that **no backup had ever been restored**, which means the
 * institute owned a file it had no evidence it could use. An untested backup is
 * a belief, and the day you test it is the worst possible day to find out.
 *
 * So this runs the restore for real, on a schedule, against a throwaway
 * database, and compares row counts table by table with the live one. If the
 * dump cannot be replayed, or replays short, the command exits non-zero and the
 * monitor pages somebody — on an ordinary Tuesday, not during an incident.
 *
 * **MySQL only, by design.** {@see DatabaseBackup} only emits `CREATE TABLE`
 * on MySQL — on SQLite the dump is INSERTs against a schema that must already
 * exist, so there is nothing to restore *into*. Production is MySQL and that is
 * the leg that matters; the CI MySQL leg exercises this path.
 */
class BackupVerify extends Command
{
    protected $signature = 'backup:verify
        {file? : Dump to verify (default: the newest in the backup path)}
        {--keep-scratch : Leave the scratch database behind for inspection}';

    protected $description = 'Restore the newest dump into a scratch database and compare row counts';

    private DatabaseBackup $backup;

    public function handle(DatabaseBackup $backup): int
    {
        $this->backup = $backup;

        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->error("backup:verify needs MySQL; this connection is {$driver}.");
            $this->line('The dump only carries CREATE TABLE on MySQL, so there is nothing to restore into.');

            return self::FAILURE;
        }

        $file = $this->argument('file') ?: $this->newestDump();

        if ($file === null) {
            $this->error('No dump found in '.config('backup.path').'. Run backup:run first.');

            return self::FAILURE;
        }

        if (! is_readable($file)) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }

        $live = config('database.connections.mysql.database');
        $scratch = config('backup.verify_database');

        // The command drops this database. If it were ever pointed at the live
        // one, a routine drill would destroy the thing it was drilling for.
        if ($scratch === $live || $scratch === '' || $scratch === null) {
            $this->error("Refusing to run: backup.verify_database ({$scratch}) must be set and must differ from the live database ({$live}).");

            return self::FAILURE;
        }

        $this->line('Verifying <info>'.basename($file).'</info> into <comment>'.$scratch.'</comment>');

        [$expected, $baseline] = $this->expectedCounts($file, $backup);

        $this->line("Baseline: <comment>{$baseline}</comment>");

        try {
            $this->restore($file, $scratch);
            $actual = $this->countsIn($scratch, array_keys($expected));
        } catch (\Throwable $e) {
            $this->dropScratch($scratch);
            $this->error('Restore failed: '.$e->getMessage());
            $this->audit($file, false, ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $rows = [];
        $mismatches = 0;

        foreach ($expected as $table => $count) {
            $got = $actual[$table] ?? null;
            $ok = $got === $count;
            $mismatches += $ok ? 0 : 1;

            $rows[] = [$table, $count, $got ?? '—', $ok ? 'ok' : 'MISMATCH'];
        }

        $this->table(['Table', 'Live', 'Restored', ''], $rows);

        if (! $this->option('keep-scratch')) {
            $this->dropScratch($scratch);
        }

        $this->audit($file, $mismatches === 0, [
            'tables' => count($expected),
            'mismatches' => $mismatches,
        ]);

        if ($mismatches > 0) {
            $this->error("{$mismatches} table(s) did not come back with the row count they went in with.");

            return self::FAILURE;
        }

        $this->info(count($expected).' tables restored and matched. This dump is usable.');

        return self::SUCCESS;
    }

    /**
     * What the restored database should contain, and where that claim comes from.
     *
     * The manifest written beside the dump is the only baseline that can
     * actually be right. The live database is a moving target — the roll grows,
     * the audit log only ever appends — so a Sunday drill replaying Tuesday's
     * dump would report a mismatch on nearly every table and teach everyone to
     * ignore the drill. That is the failure mode worth designing against: a
     * check that cries wolf is a check nobody reads.
     *
     * Dumps taken before manifests existed still verify, against live, with the
     * drift called out so the operator can judge it.
     *
     * @return array{0: array<string,int>, 1: string}
     */
    private function expectedCounts(string $file, DatabaseBackup $backup): array
    {
        $manifest = $file.'.json';

        if (is_readable($manifest)) {
            $data = json_decode((string) file_get_contents($manifest), true);

            if (is_array($data) && isset($data['counts']) && is_array($data['counts'])) {
                return [
                    array_map('intval', $data['counts']),
                    'manifest taken '.($data['taken_at'] ?? 'unknown'),
                ];
            }
        }

        $this->warn('No manifest beside this dump — falling back to live row counts.');
        $this->warn('Any change since the dump was taken will read as a mismatch. Judge the table below accordingly.');

        return [$backup->summary(), 'live database (drift expected)'];
    }

    /**
     * Replay the dump into a freshly created scratch database.
     *
     * The whole file is read into memory rather than split into statements:
     * splitting on `;` corrupts any row containing one, and this dataset
     * compresses to a couple of megabytes. If the roll ever grows past what
     * `memory_limit` will hold, this is the line that will say so, loudly.
     */
    private function restore(string $file, string $scratch): void
    {
        DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
        DB::statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        Config::set('database.connections.restore_check', array_merge(
            config('database.connections.mysql'),
            ['database' => $scratch],
        ));

        DB::purge('restore_check');

        $sql = file_get_contents(
            str_ends_with($file, '.gz') ? 'compress.zlib://'.$file : $file
        );

        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('Dump is empty or unreadable.');
        }

        DB::connection('restore_check')->unprepared($sql);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string,int>
     */
    private function countsIn(string $scratch, array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            try {
                $counts[$table] = DB::connection('restore_check')->table($table)->count();
            } catch (\Throwable) {
                // Missing table stays absent, which reads as a mismatch below
                // rather than aborting the whole report at the first gap.
            }
        }

        return $counts;
    }

    private function dropScratch(string $scratch): void
    {
        try {
            DB::purge('restore_check');
            DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
        } catch (\Throwable $e) {
            $this->warn("Could not drop scratch database {$scratch}: ".$e->getMessage());
        }
    }

    private function newestDump(): ?string
    {
        $files = $this->backup->dumpsIn((string) config('backup.path'));

        return $files === [] ? null : end($files);
    }

    private function audit(string $file, bool $passed, array $context): void
    {
        Audit::record($passed ? 'Backup restore drill passed' : 'Backup restore drill FAILED', null, [
            'subject_label' => basename($file),
            'context' => $context,
        ]);
    }
}
