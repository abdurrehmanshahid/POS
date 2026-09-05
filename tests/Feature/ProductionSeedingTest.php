<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\User;
use App\Services\Import\RollReader;
use App\Services\Import\RollResolver;
use App\Services\Import\RollRow;
use App\Services\RecordRemoval;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
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
     * ...and he still holds no password anybody knows.
     *
     * These seven accounts were created switched off, on the reasoning that the
     * institute had asked for the history to load rather than for seven new
     * logins to appear. That left them looking, on the staff screen, exactly
     * like accounts that were simply broken — and an administrator issuing a
     * temporary password to one of them during a client demonstration was told
     * "Incorrect username or password", which was not true.
     *
     * The institute has since confirmed the seven are current staff, so
     * `..._activate_the_officer_accounts_the_roll_created` switches them on.
     * What has NOT changed is the part this test exists to pin down: no
     * migration has ever handed out a working credential. The password is 32
     * random characters that were hashed and discarded in the same expression,
     * and `must_reset_password` guarantees the temporary one an administrator
     * issues is replaced at first sign-in by one only its holder knows.
     * Activation grants an account, not access.
     */
    public function test_that_officer_holds_no_password_anybody_knows(): void
    {
        $officer = User::where('username', 'aliraza')->firstOrFail();

        $this->assertTrue((bool) $officer->must_reset_password);
        $this->assertNull($officer->last_login_at, 'A migration-made account has never been used by anyone.');

        foreach (['password', 'aliraza', 'Bbt@Officer1', 'ali.raza@bbt.edu.pk', ''] as $guess) {
            $this->assertFalse(Hash::check($guess, $officer->password), 'A migration must not leave a guessable password behind.');
        }
    }

    /**
     * The seven are switched on, and the trail says so.
     *
     * A privilege that appears with nothing to explain it is exactly what an
     * auditor comes looking for, and "a deploy did this, on this date, for this
     * reason" is an answer. The rows are written as a system actor because no
     * person was at a keyboard.
     */
    public function test_the_roll_officers_are_activated_and_the_activation_is_recorded(): void
    {
        $usernames = ['aliraza', 'sofia', 'mariyam', 'shumailaltaf', 'emanashraf', 'iqraijaz', 'ayaanali'];

        $inactive = User::whereIn('username', $usernames)->where('is_active', false)->pluck('username')->all();
        $this->assertSame([], $inactive, 'These accounts are confirmed staff and should be able to be given access.');

        $this->assertSame(
            7,
            AuditLog::where('action', 'Account activated')->where('actor_type', 'system')->count(),
            'Seven accounts gained the ability to sign in with nothing in the trail to say why.'
        );
    }

    /**
     * Re-running migrations must not hand back access somebody revoked.
     *
     * The activation only touches an account still in the exact state the
     * import left it — switched off and never used. Once a person has signed
     * in, switching them off is a decision an administrator made about a
     * working account, and a deploy is not allowed to overrule it. This is the
     * same rule {@see RecordRemoval::restore()} follows.
     */
    public function test_a_deliberate_deactivation_survives_the_migration_running_again(): void
    {
        $officer = User::where('username', 'sofia')->firstOrFail();
        $officer->forceFill(['is_active' => false, 'last_login_at' => now()])->save();

        (require database_path('migrations/2026_09_05_000001_activate_the_officer_accounts_the_roll_created.php'))->up();

        $this->assertFalse(
            (bool) $officer->fresh()->is_active,
            'Re-running migrations restored access an administrator had deliberately revoked.'
        );
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
