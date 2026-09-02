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
     * B-08's SECOND half, and it is expected to fail the day it is fixed.
     *
     * Nine courses the roll names — Super Kid Camp, Shopify, the Part-A entries
     * — exist only in `DemoDataSeeder`, with invented fees and invented
     * trainers. They are real courses the institute sells, so the fix is a
     * catalogue migration built from the institute's own numbers, not a guess
     * made here.
     *
     * Asserting the gap rather than skipping it keeps the number honest and
     * makes the fix impossible to miss: when the catalogue migration lands, this
     * test fails with the new, lower count and is deleted in the same change.
     * A `markTestSkipped` would have gone quiet instead, which is how the
     * original problem survived.
     */
    public function test_the_courses_still_missing_from_production_are_the_known_nine(): void
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

        $this->assertNotSame([], $unknown,
            'B-08 is fixed: the catalogue migration has landed. Delete this test and close B-08.');

        // The catalogue migration added the Part-B and Level-2 entries and
        // assumed the base courses were already there. They were — in demo data.
        $this->assertSame(0, Course::where('code', 'SKC-101')->count());
        $this->assertGreaterThanOrEqual(200, array_sum($unknown),
            'Roughly 208 rows name a course that only demo data creates.');
    }

    /**
     * `DemoDataSeeder` refuses to run outside local and testing.
     *
     * `DatabaseSeeder` guards the bare `db:seed` path, but that guard is in the
     * caller — nothing stopped `db:seed --class=DemoDataSeeder --force` on a
     * production box, which is exactly the command checklist P3-15 warns about.
     *
     * The stakes rose when the seeder's user rows moved to `updateOrCreate` (so
     * they stop colliding with the migration that now creates `aliraza`): what
     * used to crash on a unique-email constraint would instead silently take a
     * live officer account over and set it active with `Bbt@Officer1`, a
     * password committed to this repository.
     */
    public function test_demo_data_refuses_to_seed_outside_local_and_testing(): void
    {
        app()['env'] = 'production';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/refuses to run in the "production" environment/');

            (new DemoDataSeeder)->run();
        } finally {
            app()['env'] = 'testing';
        }
    }

    /**
     * The production accounts are exactly the ones a real install should have.
     *
     * Stated as an explicit set: any demo login appearing here means demo data
     * reached a production-shaped database, which is the failure B-08 was.
     */
    public function test_no_demo_logins_exist_on_a_production_shaped_install(): void
    {
        $demo = User::whereIn('username', ['adminansar', 'fatimanoor'])->pluck('username')->all();

        $this->assertSame([], $demo, 'Demo logins must never exist on a production-shaped install.');

        // aliraza SHOULD exist — the migration creates him — but inert.
        $ali = User::where('username', 'aliraza')->first();
        $this->assertNotNull($ali);
        $this->assertFalse((bool) $ali->is_active, 'The roll CSR must not be a live login.');
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
