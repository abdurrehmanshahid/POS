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
     * Canonical name for a dump taken now.
     *
     * The convention lived as a literal in the download controller, in
     * `backup:run`'s writer and pruner, and in `backup:verify`'s finder. Change
     * the prefix in one of those and the others keep looking for the old one —
     * pruning silently stops matching and the drill silently reports "no dump
     * found", both of which look green.
     */
    public function filename(string $extension = 'sql'): string
    {
        return 'bbt-backup-'.now()->format('Y-m-d-His').'.'.$extension;
    }

    /**
     * Every dump in $path, oldest first.
     *
     * Sorted by name, not mtime: the name carries a zero-padded timestamp, so a
     * file touched or copied back from off-box keeps its true place in the order.
     *
     * @return list<string>
     */
    public function dumpsIn(string $path): array
    {
        $files = glob(rtrim($path, '/').'/bbt-backup-*.sql.gz') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Stream a full SQL dump.
     *
     * @param  string  $filename  suggested download name
     */
    public function streamSqlDump(string $filename): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            $this->writeSqlDump($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'application/sql; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Write a complete, restore-ready dump to any open stream.
     *
     * Split out from {@see streamSqlDump()} so the scheduled `backup:run` and
     * the super admin's download produce a byte-identical artefact. Two dump
     * writers would drift, and the one exercised least — the nightly one, which
     * nobody watches — is the one you find out about during a restore.
     *
     * The stream may be a gzip wrapper (`compress.zlib://`), a file, or
     * `php://output`; nothing here buffers, so peak memory is one chunk
     * regardless of destination.
     *
     * Returns the rows actually written, per table. `backup:run` stores these
     * as the dump's manifest: counted from the bytes that went into the file
     * rather than from a second COUNT(*) pass, which both halves the work and
     * removes the window in which the two could disagree.
     *
     * @param  resource  $out
     * @return array<string,int>
     */
    public function writeSqlDump($out): array
    {
        $tables = $this->tables();
        $driver = DB::connection()->getDriverName();
        $counts = [];

        $this->put($out, "-- Big Binary Tech Institute, database backup\n");
        $this->put($out, '-- Generated: '.now()->toDateTimeString()." UTC\n");
        $this->put($out, "-- Driver: {$driver}\n");
        $this->put($out, "-- Restore: import this file via phpMyAdmin > Import, or `mysql -u USER -p DB < thisfile.sql`\n\n");

        if ($driver === 'mysql') {
            // Disable FK checks for the duration: tables are written in
            // alphabetical order, not dependency order, so a child table may
            // legitimately load before its parent.
            $this->put($out, "SET FOREIGN_KEY_CHECKS=0;\n");
            $this->put($out, "SET NAMES utf8mb4;\n");
            $this->put($out, "START TRANSACTION;\n\n");
        }

        foreach ($tables as $table) {
            $this->put($out, "\n-- ----------------------------\n-- Table: {$table}\n-- ----------------------------\n");

            if ($driver === 'mysql') {
                $this->put($out, "DROP TABLE IF EXISTS `{$table}`;\n");
                $create = DB::selectOne("SHOW CREATE TABLE `{$table}`");
                $this->put($out, (array_values((array) $create)[1] ?? '').";\n\n");
            }

            $counts[$table] = $this->writeInserts($out, $table, $driver);
        }

        if ($driver === 'mysql') {
            $this->put($out, "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n");
        }

        return $counts;
    }

    /**
     * Write INSERT statements for one table, chunked by primary key so memory
     * stays flat. `orderBy` is required for chunkById to be deterministic.
     *
     * @param  resource  $out
     * @return int rows written, for the caller's manifest
     */
    private function writeInserts($out, string $table, string $driver): int
    {
        $quote = $driver === 'mysql' ? '`' : '"';
        $first = true;
        $written = 0;
        $generated = $this->generatedColumns($table, $driver);

        DB::table($table)->orderBy($this->keyColumn($table))->chunk(self::CHUNK, function ($rows) use ($out, $table, $quote, $generated, &$first, &$written) {
            foreach ($rows as $row) {
                $data = (array) $row;
                $written++;

                // Generated columns are computed by the engine and cannot be
                // written to. `SELECT *` returns them, so naming them in the
                // INSERT produced a dump that always failed on restore with
                // MySQL error 3105, on `admissions.active_slot`. That made the
                // Backup & export screen, the institute's only recovery path,
                // produce files that could never be imported.
                foreach ($generated as $column) {
                    unset($data[$column]);
                }

                if ($first) {
                    $cols = implode(', ', array_map(fn ($c) => $quote.$c.$quote, array_keys($data)));
                    $this->put($out, "INSERT INTO {$quote}{$table}{$quote} ({$cols}) VALUES\n");
                    $first = false;
                } else {
                    $this->put($out, ",\n");
                }

                $this->put($out, '('.implode(', ', array_map($this->literal(...), $data)).')');
            }
        });

        $this->put($out, $first ? "-- (no rows)\n" : ";\n");

        return $written;
    }

    /**
     * Write to the dump stream, or fail loudly.
     *
     * `fwrite()` returns short or false when the destination cannot take the
     * bytes — a full disk being the realistic case on a box whose backups share
     * a volume with the database. Unchecked, the dump simply stopped early: the
     * gzip trailer was still written on close, the file comfortably cleared the
     * minimum-size floor, and `backup:run` reported success over a truncated
     * archive. That is the precise failure this whole command was written to
     * prevent, so a short write is an exception rather than a return value
     * somebody has to remember to inspect.
     *
     * @param  resource  $out
     */
    private function put($out, string $sql): void
    {
        $written = @fwrite($out, $sql);

        if ($written === false || $written < strlen($sql)) {
            throw new \RuntimeException(
                'Short write to the dump stream after '.($written === false ? '0' : $written).
                ' of '.strlen($sql).' bytes — the destination is most likely full.'
            );
        }
    }

    /**
     * Columns the engine computes and refuses to accept a value for.
     *
     * Asked of the database rather than hardcoded, so a generated column added
     * by a future migration is excluded automatically instead of silently
     * breaking restores again.
     *
     * @return list<string>
     */
    private function generatedColumns(string $table, string $driver): array
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return array_map(
                fn ($row) => (string) ((array) $row)['COLUMN_NAME'],
                DB::select(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                       AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION <> ?',
                    [$table, '']
                )
            );
        }

        if ($driver === 'sqlite') {
            // table_xinfo, not table_info: the latter omits hidden columns
            // entirely. `hidden` is 2 for VIRTUAL and 3 for STORED.
            return array_values(array_map(
                fn ($row) => (string) ((array) $row)['name'],
                array_filter(
                    DB::select('PRAGMA table_xinfo('.$table.')'),
                    fn ($row) => in_array((int) ((array) $row)['hidden'], [2, 3], true)
                )
            ));
        }

        return [];
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
