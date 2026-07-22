<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Database backup and per-table CSV export, implemented in pure PHP.
 *
 * Deliberately NOT `mysqldump`. On shared cPanel hosting you have no shell,
 * `exec()`/`proc_open()` are usually disabled, and the mysqldump binary is
 * frequently absent or a different major version from the server. Shelling out
 * would work on a laptop and fail silently on the machine that actually matters
 *, and a backup feature that fails silently is worse than none at all, because
 * you only discover it the day you need it.
 *
 * So the dump is generated from PDO through Laravel's own connection: whatever
 * the app can read, it can back up.
 *
 * Two properties worth knowing:
 *
 *  - **Streamed, never buffered.** Rows are yielded in chunks straight to the
 *    response, so memory use stays flat whether the table has 100 rows or a
 *    million. Shared hosting typically caps PHP at 128–256MB; building the
 *    string in memory first would blow that on a real dataset.
 *
 *  - **Restore-ready.** The output is plain SQL with `DROP TABLE IF EXISTS` +
 *    `CREATE TABLE` + `INSERT`s, wrapped in a transaction with foreign-key
 *    checks disabled, so it can be pasted straight into phpMyAdmin's Import tab
 *, which is the realistic recovery path on this kind of hosting.
 */
class DatabaseBackup
{
    /** Rows read per query. Keeps peak memory bounded regardless of table size. */
    private const CHUNK = 500;

    /**
     * Tables never worth backing up: rebuildable caches and transient state.
     * Excluding them keeps the dump small and avoids restoring stale sessions.
     */
    private const SKIP = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    /** @return list<string> */
    public function tables(): array
    {
        return collect(Schema::getTableListing())
            ->map(fn (string $t) => str_contains($t, '.') ? explode('.', $t)[1] : $t)
            ->reject(fn (string $t) => in_array($t, self::SKIP, true) || str_starts_with($t, 'sqlite_'))
            ->sort()
            ->values()
            ->all();
    }

    /** Row counts per table, for the UI to show what is about to be exported. */
    public function summary(): array
    {
        return collect($this->tables())
            ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])
            ->all();
    }

    /**
     * Stream a full SQL dump.
     *
     * @param  string  $filename  suggested download name
     */
    public function streamSqlDump(string $filename): StreamedResponse
    {
        $tables = $this->tables();
        $driver = DB::connection()->getDriverName();

        return response()->streamDownload(function () use ($tables, $driver) {
            $out = fopen('php://output', 'w');

            fwrite($out, "-- Big Binary Tech Institute, database backup\n");
            fwrite($out, '-- Generated: '.now()->toDateTimeString()." UTC\n");
            fwrite($out, "-- Driver: {$driver}\n");
            fwrite($out, "-- Restore: import this file via phpMyAdmin > Import, or `mysql -u USER -p DB < thisfile.sql`\n\n");

            if ($driver === 'mysql') {
                // Disable FK checks for the duration: tables are written in
                // alphabetical order, not dependency order, so a child table may
                // legitimately load before its parent.
                fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
                fwrite($out, "SET NAMES utf8mb4;\n");
                fwrite($out, "START TRANSACTION;\n\n");
            }

            foreach ($tables as $table) {
                fwrite($out, "\n-- ----------------------------\n-- Table: {$table}\n-- ----------------------------\n");

                if ($driver === 'mysql') {
                    fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");
                    $create = DB::selectOne("SHOW CREATE TABLE `{$table}`");
                    fwrite($out, (array_values((array) $create)[1] ?? '').";\n\n");
                }

                $this->writeInserts($out, $table, $driver);
            }

            if ($driver === 'mysql') {
                fwrite($out, "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n");
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'application/sql; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Write INSERT statements for one table, chunked by primary key so memory
     * stays flat. `orderBy` is required for chunkById to be deterministic.
     *
     * @param  resource  $out
     */
    private function writeInserts($out, string $table, string $driver): void
    {
        $quote = $driver === 'mysql' ? '`' : '"';
        $first = true;

        DB::table($table)->orderBy($this->keyColumn($table))->chunk(self::CHUNK, function ($rows) use ($out, $table, $quote, &$first) {
            foreach ($rows as $row) {
                $data = (array) $row;

                if ($first) {
                    $cols = implode(', ', array_map(fn ($c) => $quote.$c.$quote, array_keys($data)));
                    fwrite($out, "INSERT INTO {$quote}{$table}{$quote} ({$cols}) VALUES\n");
                    $first = false;
                } else {
                    fwrite($out, ",\n");
                }

                fwrite($out, '('.implode(', ', array_map($this->literal(...), $data)).')');
            }
        });

        fwrite($out, $first ? "-- (no rows)\n" : ";\n");
    }

    /** Best available ordering column, id where present, else the first column. */
    private function keyColumn(string $table): string
    {
        $columns = Schema::getColumnListing($table);

        return in_array('id', $columns, true) ? 'id' : ($columns[0] ?? 'id');
    }

    /**
     * Render one value as a SQL literal.
     *
     * Escaping is done via PDO::quote, which applies the connection's own
     * charset-aware rules. Hand-rolled addslashes() is the classic way to
     * produce a dump that silently corrupts on multi-byte data.
     */
    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }

    /**
     * Stream one table as CSV. UTF-8 with a BOM and CRLF line endings, matching
     * the students export (spec §11) so Excel opens both identically.
     */
    public function streamTableCsv(string $table, string $filename): StreamedResponse
    {
        abort_unless(in_array($table, $this->tables(), true), 404);

        return response()->streamDownload(function () use ($table) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $columns = Schema::getColumnListing($table);
            $this->putRow($out, $columns);

            DB::table($table)->orderBy($this->keyColumn($table))->chunk(self::CHUNK, function ($rows) use ($out, $columns) {
                foreach ($rows as $row) {
                    $data = (array) $row;
                    $this->putRow($out, array_map(fn ($c) => $data[$c] ?? '', $columns));
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param resource $out */
    private function putRow($out, array $values): void
    {
        $cells = array_map(function ($v) {
            $v = (string) ($v ?? '');

            return '"'.str_replace('"', '""', $v).'"';
        }, $values);

        fwrite($out, implode(',', $cells)."\r\n");
    }
}
