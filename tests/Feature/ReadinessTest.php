<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/ready`, the endpoint an external monitor watches and a stranger can poll.
 *
 * Two properties are being defended here and they pull against each other:
 *
 *   It must tell the TRUTH about whether this box can serve a fee payment —
 *   which means actually touching MySQL and the filesystem, not just returning
 *   200 because PHP is running.
 *
 *   It must tell a STRANGER nothing. It is unauthenticated and permanently
 *   reachable, so a helpful "SQLSTATE[HY000] Connection refused for user
 *   institute_pos at 127.0.0.1" is a reconnaissance gift served on a loop.
 *
 * The tests below assert both halves, and the third one asserts the property
 * that makes the monitor's alerting rule correct: /ready goes 503 during
 * maintenance while /up stays 200.
 */
class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_bare_200_when_everything_is_healthy(): void
    {
        $response = $this->get('/ready');

        $response->assertOk();
        // Bare. No JSON, no hostname, no version, no diagnostic body — see the
        // ReadinessController docblock for why an empty response is the point.
        $this->assertSame('', $response->getContent());
    }

    public function test_it_returns_503_and_leaks_nothing_when_the_database_is_unreachable(): void
    {
        // Point the DEFAULT at a connection that cannot open, which is the
        // closest honest simulation of "MySQL is down" available in a test that
        // must not actually stop MySQL.
        //
        // Deliberately a NEW connection name, and deliberately no DB::purge().
        // The suite runs on :memory: SQLite, which RefreshDatabase keeps alive
        // as a single PDO handle for the whole run — purging it, or repointing
        // the name it is cached under, destroys the database out from under
        // every test that follows. Adding a name leaves that handle cached and
        // untouched.
        $original = config('database.default');

        config(['database.connections.ready_probe_broken' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent-path/institute-not-here.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        try {
            config(['database.default' => 'ready_probe_broken']);

            $response = $this->get('/ready');

            $response->assertStatus(503);

            $body = $response->getContent();
            $this->assertSame('', $body);

            // Belt and braces: even if the body were ever made non-empty, none
            // of these may appear in it.
            foreach (['SQLSTATE', 'institute-not-here', 'nonexistent-path', base_path()] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        } finally {
            config(['database.default' => $original]);
        }
    }

    /**
     * The behaviour the monitoring rule depends on.
     *
     * `/up` is registered by Laravel's `health:` option, which calls
     * PreventRequestsDuringMaintenance::except() on it — so it answers 200
     * throughout a deploy and is a liveness probe.
     *
     * `/ready` is registered without that exception, so it 503s for the
     * duration of every release. That is CORRECT and is how a failed deploy
     * that left maintenance mode on gets noticed. It is also exactly why the
     * external monitor must alert on two consecutive failures rather than one:
     * a single-failure rule pages on every routine release and gets muted
     * within a fortnight.
     */
    public function test_ready_reports_unavailable_during_maintenance_while_up_does_not(): void
    {
        Artisan::call('down');

        try {
            $this->get('/ready')->assertStatus(503);
            $this->get('/up')->assertOk();
        } finally {
            Artisan::call('up');
        }
    }

    public function test_it_does_not_start_a_session(): void
    {
        // SESSION_DRIVER is `database` in production. Registered inside the
        // `web` group, a monitor polling every five minutes would write 288
        // junk session rows a day into the database this endpoint checks.
        $before = DB::table('sessions')->count();

        $this->get('/ready')->assertOk();
        $this->get('/ready')->assertOk();

        $this->assertSame($before, DB::table('sessions')->count());
    }
}
