<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The unattended half of the backup story.
 *
 * `docs/DEPLOYMENT.md` has always told the institute to take a dump "before
 * every upgrade", and for the whole of the build nobody had — because doing so
 * meant a person remembering to click a button. These cover the scheduled path
 * that removes the person.
 *
 * The assertions worth understanding are the two failure ones. A backup command
 * that returns success while writing nothing is the exact shape of the disaster
 * it exists to prevent: the cron job stays green for months and the gap is
 * discovered on the day the database is already gone. So the tests care less
 * that a good run succeeds than that a bad run is *loud*.
 */
class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->path = storage_path('framework/testing/backups-'.getmypid());
        config(['backup.path' => $this->path]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->path);

        parent::tearDown();
    }

    /** @return list<string> */
    private function dumps(): array
    {
        return glob($this->path.'/bbt-backup-*.sql.gz') ?: [];
    }

    public function test_it_writes_a_compressed_dump_and_creates_the_directory(): void
    {
        $this->assertDirectoryDoesNotExist($this->path);

        $this->artisan('backup:run')->assertSuccessful();

        $this->assertCount(1, $this->dumps());

        // Readable back through the gzip wrapper, and actually carrying data —
        // a file that exists but cannot be decompressed is not a backup.
        $sql = file_get_contents('compress.zlib://'.$this->dumps()[0]);

        $this->assertStringContainsString('Big Binary Tech Institute', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
    }

    public function test_the_dump_carries_the_money_tables(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $sql = file_get_contents('compress.zlib://'.$this->dumps()[0]);

        // The ledger is the reason the backup exists. If a schema change ever
        // drops one of these from the dump, this fails rather than the institute
        // finding out during a restore.
        foreach (['payments', 'challans', 'students', 'audit_logs'] as $table) {
            $this->assertStringContainsString($table, $sql, "Dump is missing {$table}");
        }
    }

    public function test_it_writes_a_manifest_of_row_counts_beside_the_dump(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $manifest = $this->dumps()[0].'.json';

        $this->assertFileExists($manifest);

        $data = json_decode(file_get_contents($manifest), true);

        $this->assertSame(basename($this->dumps()[0]), $data['dump']);
        $this->assertArrayHasKey('payments', $data['counts']);
        $this->assertSame(DB::table('students')->count(), $data['counts']['students']);
    }

    public function test_the_manifest_is_captured_before_the_backups_own_audit_row(): void
    {
        // The bug this pins down: `backup:run` writes an audit row *after* the
        // dump, so a manifest taken afterwards would claim one more audit row
        // than the dump actually contains — and the weekly drill would report a
        // mismatch on audit_logs every single week until everyone ignored it.
        $before = DB::table('audit_logs')->count();

        $this->artisan('backup:run')->assertSuccessful();

        $data = json_decode(file_get_contents($this->dumps()[0].'.json'), true);

        $this->assertSame($before, $data['counts']['audit_logs']);
        $this->assertSame($before + 1, DB::table('audit_logs')->count());
    }

    public function test_pruning_removes_the_manifest_with_its_dump(): void
    {
        mkdir($this->path, 0750, true);

        file_put_contents($this->path.'/bbt-backup-2020-01-01-000000.sql.gz', 'placeholder');
        file_put_contents($this->path.'/bbt-backup-2020-01-01-000000.sql.gz.json', '{}');

        $this->artisan('backup:run', ['--keep' => 1])->assertSuccessful();

        // A manifest describing a dump that no longer exists is litter that
        // later reads as evidence a backup was taken.
        $this->assertFileDoesNotExist($this->path.'/bbt-backup-2020-01-01-000000.sql.gz');
        $this->assertFileDoesNotExist($this->path.'/bbt-backup-2020-01-01-000000.sql.gz.json');
    }

    public function test_it_records_the_backup_in_the_audit_log(): void
    {
        $before = AuditLog::count();

        $this->artisan('backup:run')->assertSuccessful();

        $entry = AuditLog::latest('id')->first();

        $this->assertSame($before + 1, AuditLog::count());
        $this->assertSame('Database backup written', $entry->action);

        // No actor: this one was taken by the machine, and the log should say so
        // rather than attributing it to whoever happened to deploy last.
        $this->assertNull($entry->actor_id);
    }

    public function test_it_prunes_to_the_retention_limit_oldest_first(): void
    {
        mkdir($this->path, 0750, true);

        foreach (['2020-01-01-000000', '2020-01-02-000000', '2020-01-03-000000'] as $stamp) {
            file_put_contents($this->path."/bbt-backup-{$stamp}.sql.gz", 'placeholder');
        }

        $this->artisan('backup:run', ['--keep' => 2])->assertSuccessful();

        $remaining = array_map('basename', $this->dumps());

        $this->assertCount(2, $remaining);

        // The two survivors are the newest: the run's own dump, and the newest
        // of the placeholders. Ordering is by name, which is why the timestamp
        // in the filename is zero-padded.
        $this->assertContains('bbt-backup-2020-01-03-000000.sql.gz', $remaining);
        $this->assertNotContains('bbt-backup-2020-01-01-000000.sql.gz', $remaining);
    }

    public function test_it_fails_loudly_when_the_dump_comes_out_suspiciously_small(): void
    {
        // The silent-failure scenario, forced: raise the floor above anything a
        // real dump could produce and confirm the command refuses to call it a
        // success — and removes the useless file rather than leaving it to be
        // mistaken for a good one.
        config(['backup.minimum_bytes' => 50_000_000]);

        $this->artisan('backup:run')->assertFailed();

        $this->assertSame([], $this->dumps());
    }

    public function test_it_fails_rather_than_writing_somewhere_it_cannot(): void
    {
        $this->artisan('backup:run', ['--path' => '/proc/nonexistent/backups'])
            ->assertFailed();
    }

    public function test_verify_refuses_to_run_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('This asserts the guard on non-MySQL drivers.');
        }

        // Not a limitation worth hiding: the dump only carries CREATE TABLE on
        // MySQL, so on SQLite there is nothing to restore into. Better to say so
        // than to appear to have drilled a restore that never happened.
        $this->artisan('backup:verify')->assertFailed();
    }
}
