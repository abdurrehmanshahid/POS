<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Services\Import\RollReader;
use App\Services\Import\RollResolver;
use App\Services\Import\RollRow;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What the roll can do on a PRODUCTION-shaped database (B-08).
 *
 * Every other test in this suite calls `$this->seed(DatabaseSeeder::class)`,
 * which on `testing` runs `DemoDataSeeder` — nine courses, three logins with
 * committed passwords, thirty fictional students. Production runs neither: the
 * guard is in `DatabaseSeeder`, and go-live checklist **P2-09** repeats it as an
 * instruction, *only `RolePermissionSeeder` and `SuperAdminSeeder`, by name*.
 *
 * That gap is how B-08 hid for two weeks. `SHIP-READINESS.md` recorded the roll
 * import as closed on the strength of 447 rows that only imported because demo
 * data happened to supply the CSR account and nine of the courses they name. On
 * the real box the same file would have refused 435 rows, and it would have done
 * it during the P3 bootstrap with the counter waiting.
 *
 * So this class deliberately does NOT seed demo data. It is the only place that
 * asks what production will actually see.
 */
class ProductionSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_ROLL = 'student_details_report (45).xlsx';

    protected function setUp(): void
    {
        parent::setUp();
        config(['institute.today' => '2026-07-15']);
        Carbon::setTestNow('2026-07-15 10:00:00');

        // Exactly what P2-09 permits. No DatabaseSeeder, so no demo data.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SuperAdminSeeder::class);
    }

    /**
     * The officer named on 435 of the roll's 478 lines has an account.
     *
     * Created by a migration rather than a seeder, because a seeder that
     * production is forbidden to run cannot be where a production dependency
     * lives.
     */
    public function test_the_officer_who_signed_most_of_the_roll_exists_without_demo_data(): void
    {
        $officer = User::where('username', 'aliraza')->first();

        $this->assertNotNull($officer, 'Ali Raza is named on 435 roll lines and must exist on production.');
        $this->assertSame('officer', $officer->role_id);
    }

    /**
     * ...and he cannot sign in.
     *
     * The institute asked for the history to load, not for a new person to gain
     * access. An account a migration creates is one nobody consciously granted,
     * so it is inert until an administrator activates it on the Staff & Roles
     * screen — the same audited path as any new joiner.
     */
    public function test_that_officer_is_created_inactive_and_must_reset(): void
    {
        $officer = User::where('username', 'aliraza')->firstOrFail();

        $this->assertFalse((bool) $officer->is_active, 'A migration must not hand out live access.');
        $this->assertTrue((bool) $officer->must_reset_password);
    }

    /**
     * Every officer the roll credits has an account, not just most of them.
     *
     * Stated as a set rather than a count so that adding a seventh officer to
     * the roll fails here with the name that is missing, rather than with an
     * arithmetic mismatch somebody has to go and decode.
     */
    public function test_every_csr_named_in_the_roll_has_an_account(): void
    {
        $expected = ['aliraza', 'sofia', 'mariyam', 'shumailaltaf', 'emanashraf', 'iqraijaz', 'ayaanali'];

        $missing = array_diff($expected, User::whereIn('username', $expected)->pluck('username')->all());

        $this->assertSame([], array_values($missing), 'These CSRs are named in the roll but have no account.');
    }

    /**
     * No row is refused for want of a CSR account.
     *
     * The regression test for B-08's first half, asserted against the real file
     * rather than a fixture — the whole failure was that a fixture-shaped test
     * would have passed.
     */
    public function test_the_real_roll_is_not_refused_for_a_missing_csr(): void
    {
        $rows = $this->resolvedRoll();

        $blamed = array_filter($rows, fn (RollRow $r) => array_filter(
            $r->reasons,
            fn (string $why) => str_contains($why, 'has no account')
        ) !== []);

        $this->assertSame([], array_map(fn (RollRow $r) => $r->line, $blamed),
            'No roll row may be refused because its CSR has no account on production.');
    }

    /**
     * B-08's second half, now closed: no row is refused for an unknown course.
     *
     * This method replaces the one that asserted the gap. That earlier test was
     * written to fail the day the catalogue migration landed, and it did exactly
     * that — the message it failed with was the instruction to write this.
     *
     * Asserted against the real roll and on a production-shaped database, for
     * the same reason as the CSR check above: the whole failure was that a
     * fixture-shaped test passed while production could not resolve a course.
     */
    public function test_no_roll_row_is_refused_for_an_unknown_course(): void
    {
        $rows = $this->resolvedRoll();

        $unknown = [];
        foreach ($rows as $row) {
            foreach ($row->reasons as $why) {
                if (str_starts_with($why, 'unknown course:')) {
                    $unknown[$why] = ($unknown[$why] ?? 0) + 1;
                }
            }
        }

        $this->assertSame([], $unknown,
            'A course the roll names is missing from the catalogue on a production-shaped install.');
    }

    /**
     * Every alias points at a course that exists WITHOUT demo data.
     *
     * `RollImportTest` already asserts this, but it seeds `DatabaseSeeder`, so
     * on `testing` it has demo data and the assertion passed throughout the
     * whole of B-08. Five aliases — WD-101, DMM-101, VE-101, ODOO-301, ROB-101 —
     * pointed at codes only `DemoDataSeeder` created, and nothing caught it.
     */
    public function test_every_course_alias_resolves_without_demo_data(): void
    {
        $codes = array_values(config('roll-import.course_aliases', []));

        $missing = array_values(array_diff($codes, Course::whereIn('code', $codes)->pluck('code')->all()));

        $this->assertSame([], $missing,
            'These aliases point at course codes that do not exist on a production install.');
    }

    /** @return list<RollRow> */
    private function resolvedRoll(): array
    {
        $path = base_path(self::REAL_ROLL);

        if (! is_readable($path)) {
            $this->markTestSkipped(self::REAL_ROLL.' is gitignored and not present.');
        }

        $rows = app(RollReader::class)->read($path);
        app(RollResolver::class)->resolve($rows);

        return $rows;
    }
}
