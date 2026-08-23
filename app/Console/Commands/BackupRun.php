<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;

/**
 * Write a compressed database dump to disk.
 *
 * This is the unattended half of the backup story. The super admin screen has
 * always been able to download a dump by hand, but a backup that depends on a
 * person remembering is not a backup — it is an intention. `docs/DEPLOYMENT.md`
 * told the institute to take one "before every upgrade" and nobody ever had.
 *
 * It shares {@see DatabaseBackup::writeSqlDump()} with that screen on purpose,
 * so the nightly artefact and the one a human downloads are the same bytes. A
 * second dump writer would drift, and the one nobody watches is the one you
 * discover is broken during a restore.
 *
 * **This command fails loudly.** Every failure path exits non-zero so that cron
 * mail, the systemd timer's status, and the uptime monitor all agree something
 * is wrong. The failure mode this is written against is not "the dump broke" —
 * it is "the dump broke in March and stayed green until August".
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--keep= : How many dumps to retain (default: config backup.keep)}
        {--path= : Where to write (default: config backup.path)}';

    protected $description = 'Write a compressed, restore-ready database dump and prune old ones';

    private DatabaseBackup $backup;

    /**
     * The open lock handle, held for the life of the process.
     *
     * Kept as a property purely so PHP does not garbage-collect the resource
     * and release the flock out from under a dump that is still running.
     *
     * @var resource|null
     */
    private $lock = null;

    /**
     * Take the lock, do the work, release the lock — whatever the work did.
     *
     * The release is explicit rather than left to process exit, and that is not
     * tidiness. flock() attaches to the open file description, so a second
     * `fopen` of the same path inside ONE process gets a second description and
     * blocks on the first. Every artisan invocation is its own process in
     * production, so relying on exit looked fine — and then the test suite, one
     * process running the command eight times, saw seven of them no-op with a
     * cheerful "another backup is already running". Anything else that ever
     * calls this twice in a process (a queued job, `schedule:run` on a busy
     * tick) would have hit exactly the same wall, silently.
     */
    public function handle(DatabaseBackup $backup): int
    {
        if (! $this->acquireLock()) {
            return self::SUCCESS;
        }

        try {
            return $this->takeBackup($backup);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @return bool false when another dump holds the lock and this run should stand down
     */
    private function acquireLock(): bool
    {
        // ---- The lock ------------------------------------------------------
        //
        // Three things start this command: the hourly scheduler, the mandatory
        // dump at the top of deploy.sh, and a human at a prompt. Any two of
        // them can coincide — a release approved on the hour is not a rare
        // event — and two writers producing dumps into one directory at once
        // is how you get a pile of half-written archives that all look fine in
        // `ls`.
        //
        // The lock is taken HERE rather than wrapped around one caller, because
        // a lock only one of three paths takes is not a lock. The schedule's
        // `withoutOverlapping` stays as well: it is a cache entry that keeps
        // the scheduler from stacking, whereas this is a kernel lock that also
        // covers the two callers the scheduler knows nothing about.
        //
        // LOCK_NB: a backup that queues behind another backup is a backup
        // nobody is waiting for. Exiting SUCCESS rather than FAILURE is
        // deliberate — a dump was taken, by the other process, moments ago.
        // Failing would page an operator about a system working correctly, and
        // an alert that cries wolf is an alert that gets muted.
        $lockPath = (string) config('backup.lock');

        if ($lockPath === '') {
            return true;
        }

        @mkdir(dirname($lockPath), 0775, true);
        $lock = @fopen($lockPath, 'c');

        if ($lock === false) {
            // Not fatal. An unopenable lock file means the box was provisioned
            // before /etc/tmpfiles.d/institute.conf existed, and refusing to
            // back up over it would turn a missing safety rail into a missing
            // backup — strictly the worse of the two.
            $this->warn("Cannot open the backup lock at {$lockPath}; proceeding without it.");

            return true;
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->warn('Another backup is already running; this run is a no-op.');

            return false;
        }

        $this->lock = $lock;

        return true;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private function takeBackup(DatabaseBackup $backup): int
    {
        $this->backup = $backup;

        $path = $this->option('path') ?: config('backup.path');
        // `is_numeric` rather than `?:`, so `--keep=0` means what it says.
        // Zero is a real setting — prune() reads it as "keep everything" — and
        // `?:` quietly turned it back into the 14-dump default.
        $keep = is_numeric($this->option('keep'))
            ? (int) $this->option('keep')
            : (int) config('backup.keep');

        // Suppressed because the failure is handled right here: an unwritable
        // path is a reportable condition, not a stack trace in the cron mail.
        if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
            $this->error("Cannot create backup directory: {$path}");

            return self::FAILURE;
        }

        if (! is_writable($path)) {
            $this->error("Backup directory is not writable: {$path}");

            return self::FAILURE;
        }

        $file = rtrim($path, '/').'/'.$backup->filename('sql.gz');

        // Row counts come from the dump pass itself, and are stored beside it.
        //
        // This is what makes the weekly drill meaningful. Comparing a restored
        // dump against the *live* database can only ever fail: the roll moves,
        // the audit log is append-only, and by the time a Sunday drill replays
        // Tuesday's dump the two legitimately disagree about nearly every table.
        // Measured against the manifest instead, the question becomes the one
        // actually worth asking — did everything that went into this file come
        // back out of it?
        //
        // Counted while writing rather than by a COUNT(*) sweep beforehand:
        // that sweep was a second full read of every table on a job that runs
        // nightly and on every deploy, and it described the database a moment
        // before the dump rather than the file that was actually produced.
        $manifest = $file.'.json';

        // gzip through the stream wrapper rather than dumping then compressing:
        // the uncompressed dump never exists on disk, so a backup cannot fill
        // the volume that the database it is protecting also lives on.
        $out = @fopen('compress.zlib://'.$file, 'w');

        if ($out === false) {
            $this->error("Cannot open {$file} for writing.");

            return self::FAILURE;
        }

        try {
            $expected = $backup->writeSqlDump($out);
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($file);
            $this->error('Dump failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // zlib buffers, so the last block — and any ENOSPC that comes with it —
        // surfaces on close rather than on the final write. Checking put()'s
        // writes without checking this would leave half the hole open.
        if (fclose($out) === false) {
            @unlink($file);
            $this->error("Could not flush and close {$file}. Treating the dump as failed and removing it.");

            return self::FAILURE;
        }

        clearstatcache(true, $file);
        $bytes = (int) @filesize($file);
        $minimum = (int) config('backup.minimum_bytes');

        // A dump that is technically a file but obviously empty is the exact
        // shape of the silent failure this command exists to prevent.
        if ($bytes < $minimum) {
            @unlink($file);
            $this->error("Dump was only {$bytes} bytes, below the {$minimum}-byte floor. Treating as failed and removing it.");

            return self::FAILURE;
        }

        file_put_contents($manifest, json_encode([
            'dump' => basename($file),
            'taken_at' => now()->toIso8601String(),
            'driver' => DB::connection()->getDriverName(),
            'bytes' => $bytes,
            'counts' => $expected,
        ], JSON_PRETTY_PRINT));

        $tables = count($expected);
        $pruned = $this->prune($path, $keep);

        // The activity log is the institute's own record. A dump is the most
        // sensitive artefact this system produces, so "the machine took one at
        // 02:00" belongs in the same ledger as "a person downloaded one".
        Audit::record('Database backup written', null, [
            'subject_label' => basename($file),
            'context' => [
                'bytes' => $bytes,
                'tables' => $tables,
                'pruned' => $pruned,
                'path' => $path,
            ],
        ]);

        $this->info(sprintf(
            '%s · %s · %d tables%s',
            basename($file),
            Number::fileSize($bytes, maxPrecision: 1),
            $tables,
            $pruned > 0 ? " · pruned {$pruned}" : '',
        ));

        $this->copyOffBox($path);

        return self::SUCCESS;
    }

    /**
     * Push the dump off the machine it was taken on.
     *
     * Reached only after everything above succeeded, which is the whole design:
     * a dump that failed its size floor has already been deleted, and a
     * truncated archive pushed off-box would overwrite the last good copy with
     * a broken one. Copying only what we have just verified means the remote
     * can never be worse than the local.
     *
     * `--max-age 2h` rather than a full sync: at one dump an hour this uploads
     * the one or two files that are new, so the copy is seconds regardless of
     * how many dumps are on the box. A full sync would also be a way to
     * propagate a local deletion to the remote, and the remote's retention is
     * deliberately the bucket's business, not this command's.
     *
     * Never `rclone move`, and never a local delete on success. The two
     * retentions are independent: seven days on the box for a fast restore,
     * thirty days off it for the disk-died case.
     *
     * A failure here is a WARNING, not a command failure. The dump exists and
     * is good; what is missing is the second copy. Returning FAILURE would make
     * the deploy that called this refuse, and refusing to deploy because an
     * object store had a bad minute is a worse outcome than a logged warning
     * that the operator's backup check will surface within the hour.
     */
    private function copyOffBox(string $path): void
    {
        $remote = trim((string) config('backup.rclone_remote'));

        if ($remote === '') {
            // Loud rather than silent. A box with no off-box copy has backups
            // and no disaster recovery, and that fact should appear in the log
            // every hour until somebody fixes it.
            $this->warn('BACKUP_RCLONE_REMOTE is not set — this dump stays on the same disk as the database it protects.');

            return;
        }

        $result = Process::timeout(300)->run([
            'rclone', 'copy', rtrim($path, '/'), $remote, '--max-age', '2h',
        ]);

        if ($result->successful()) {
            $this->line("  off-box copy -> {$remote}");

            return;
        }

        $this->warn("Off-box copy to {$remote} FAILED (exit {$result->exitCode()}). The local dump is good; there is no remote copy of it.");
        logger()->critical('Off-box backup copy failed', [
            'remote' => $remote,
            'exit' => $result->exitCode(),
            'stderr' => $result->errorOutput(),
        ]);
    }

    /**
     * Delete all but the newest $keep dumps.
     *
     * Ordering comes from {@see DatabaseBackup::dumpsIn()}, which is also what
     * `backup:verify` uses to pick the newest — so pruning and the drill can
     * never disagree about which dumps exist or which one is latest.
     */
    private function prune(string $path, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $files = $this->backup->dumpsIn($path);

        $stale = array_slice($files, 0, max(0, count($files) - $keep));

        foreach ($stale as $file) {
            @unlink($file);
            // The manifest is part of the dump, not a separate artefact. Leaving
            // it behind would strand a row-count file describing a dump that no
            // longer exists.
            @unlink($file.'.json');
        }

        return count($stale);
    }
}
