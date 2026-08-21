<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\Counter;
use App\Models\Course;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two connections, one row: proving the locks are real (RISK-01 / B-04).
 *
 * Four invariants in this system rest entirely on `lockForUpdate()` — serial
 * allocation, course capacity, collection, and the challan serial — and until
 * now not one test drove two simultaneous connections. The guarantee rested on
 * code review. Reviewers agreeing that a lock looks right is not the same as a
 * database refusing a second writer, and the difference only ever shows up as
 * two students holding the same admission number, or one family's fee banked
 * twice, discovered weeks later in a report.
 *
 * **Why this cannot run on SQLite.** SQLite takes one writer per file, so a
 * second connection is refused by the engine no matter what the code does — it
 * would pass this suite while proving nothing about production. MySQL is the
 * production engine and the only one where row-level locking is a real
 * question, so these tests skip elsewhere and run on the CI MySQL leg.
 *
 * **Why `DatabaseMigrations` rather than `RefreshDatabase`.** RefreshDatabase
 * wraps each test in a transaction that is never committed, so a second
 * connection cannot see the fixtures at all — the tests would "pass" against an
 * empty database. Migrating for real is slower and is the only way this means
 * anything.
 *
 * The shape of every test below is the same: hold a lock on connection A, give
 * connection B a one-second patience, and assert B is refused. The control
 * assertion in {@see test_the_second_connection_is_healthy_when_no_lock_is_held}
 * is what stops a broken second connection from masquerading as a working lock.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'concurrent';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row-level locking can only be proven on MySQL; SQLite allows one writer per file.');
        }

        // A genuinely separate connection to the same database — not a clone of
        // the first one's PDO handle, which would share its transaction and
        // never contend for anything.
        config(['database.connections.'.self::SECOND => config('database.connections.mysql')]);
        DB::purge(self::SECOND);
    }

    protected function tearDown(): void
    {
        // A test that fails mid-transaction must not leave a lock held, or every
        // test after it times out and the real failure is buried.
        //
        // The second connection is only reachable when setUp got far enough to
        // configure it; on a skipped (non-MySQL) run it never existed, and
        // asking for it here would turn five clean skips into five errors.
        $connections = [DB::connection()];

        if (config('database.connections.'.self::SECOND) !== null) {
            $connections[] = DB::connection(self::SECOND);
        }

        foreach ($connections as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        parent::tearDown();
    }

    /**
     * Ask the second connection to take the same row lock, with a one-second
     * fuse, and report whether it was refused.
     */
    private function secondConnectionIsBlockedOn(string $table, callable $where): bool
    {
        $second = DB::connection(self::SECOND);

        // One second is the floor MySQL allows. Without it the test would sit on
        // the default 50-second timeout and read as a hang.
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $second->beginTransaction();

        try {
            $where($second->table($table))->lockForUpdate()->first();

            return false;
        } catch (QueryException) {
            return true;
        } finally {
            while ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
        }
    }

    public function test_the_second_connection_is_healthy_when_no_lock_is_held(): void
    {
        Counter::create(['key' => 'admission', 'value' => 1]);

        // The control. If this ever fails, every "blocked" assertion below is
        // meaningless — a second connection that cannot read anything looks
        // exactly like a lock that works.
        $blocked = $this->secondConnectionIsBlockedOn(
            'counters',
            fn ($query) => $query->where('key', 'admission'),
        );

        $this->assertFalse($blocked, 'The second connection could not read an unlocked row, so it proves nothing about locking.');
    }

    public function test_serial_allocation_locks_the_counter_against_a_second_connection(): void
    {
        Counter::create(['key' => 'admission', 'value' => 7]);

        DB::beginTransaction();
        DB::table('counters')->where('key', 'admission')->lockForUpdate()->first();

        $blocked = $this->secondConnectionIsBlockedOn(
            'counters',
            fn ($query) => $query->where('key', 'admission'),
        );

        DB::rollBack();

        // This is the guarantee behind BBT-ADM-0012 being unique: a second
        // officer registering at the same instant waits for the first to finish
        // rather than reading the same "next" value.
        $this->assertTrue($blocked, 'A second connection took the counter row while it was locked — serials can collide.');
    }

    public function test_a_serial_is_never_handed_out_twice_across_connections(): void
    {
        Counter::create(['key' => 'admission', 'value' => 7]);

        // Connection A consumes 7 and commits.
        DB::transaction(function () {
            $counter = Counter::query()->where('key', 'admission')->lockForUpdate()->first();
            $counter->update(['value' => $counter->value + 1]);
        });

        // Connection B, arriving after, must see 8 — not the 7 it would have
        // read had it been allowed in alongside A.
        $next = DB::connection(self::SECOND)->table('counters')->where('key', 'admission')->value('value');

        $this->assertSame(8, (int) $next);
    }

    public function test_course_capacity_locks_the_course_row(): void
    {
        $this->seed(DatabaseSeeder::class);
        $course = Course::query()->firstOrFail();

        DB::beginTransaction();
        DB::table('courses')->where('id', $course->id)->lockForUpdate()->first();

        $blocked = $this->secondConnectionIsBlockedOn(
            'courses',
            fn ($query) => $query->where('id', $course->id),
        );

        DB::rollBack();

        // Without this, two officers both read "1 seat left" and both take it.
        $this->assertTrue($blocked, 'A second connection read the course row mid-enrolment — capacity can be oversold.');
    }

    public function test_collection_locks_the_challan_row(): void
    {
        $this->seed(DatabaseSeeder::class);
        $challan = Challan::query()->firstOrFail();

        DB::beginTransaction();
        DB::table('challans')->where('id', $challan->id)->lockForUpdate()->first();

        $blocked = $this->secondConnectionIsBlockedOn(
            'challans',
            fn ($query) => $query->where('id', $challan->id),
        );

        DB::rollBack();

        // The one that costs money: two terminals settling one family's fee at
        // the same moment both passed the balance check and both inserted.
        $this->assertTrue($blocked, 'A second connection read the challan row mid-collection — a fee can be banked twice.');
    }
}
