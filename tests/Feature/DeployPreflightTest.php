<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `deploy:preflight`, the half of the deploy gate that asks Laravel rather than
 * grep.
 *
 * `deploy.sh` checks `.env` with shell tools first, and that layer refuses
 * fastest. This command is the layer that can answer the two questions the
 * shell cannot: what does Laravel actually RESOLVE (a key can be present and
 * still not mean what it looks like), and does the runtime WORK (is MySQL
 * reachable, is storage writable).
 *
 * These tests exist because a gate that cannot fail is worse than no gate: it
 * is still trusted. Each one below turns a single setting unsafe and asserts
 * the command refuses, so that a future refactor which quietly drops an
 * assertion is caught here rather than on the night of a release.
 */
class DeployPreflightTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The settings a correctly configured production box would resolve to.
     *
     * Applied as config rather than .env because that is what the command
     * reads — the whole point of this layer is that it sees the EFFECTIVE
     * values, not the file they came from.
     */
    private function productionConfig(array $overrides = []): void
    {
        config(array_merge([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://pos.bbt.edu.pk',
            'app.trusted_proxies' => '127.0.0.1',
            'institute.today' => null,
            'session.driver' => 'database',
            'session.secure' => true,
            'cache.default' => 'database',
            'queue.default' => 'database',
            'backup.path' => storage_path('app/preflight-backups'),
            'backup.keep' => 168,
            'backup.rclone_remote' => 'r2:institute-backups',
        ], $overrides));

        @mkdir(storage_path('app/preflight-backups'), 0775, true);
    }

    /**
     * The database assertions want mysql, and the suite runs on SQLite, so the
     * two DB checks are exercised separately below rather than being made to
     * pass here. Everything else must be green.
     *
     * @return array{code:int,output:string}
     */
    private function preflight(): array
    {
        // Artisan::call(), not $this->artisan(). The latter returns a
        // PendingCommand whose output goes to an expectation buffer rather
        // than to Artisan::output(), so every assertion on the text silently
        // compared against an empty string — a test that could not fail.
        $code = Artisan::call('deploy:preflight');

        return ['code' => $code, 'output' => Artisan::output()];
    }

    public function test_it_refuses_the_default_test_environment(): void
    {
        // No production config applied. The command must not be quietly
        // permissive about the environment it happens to find itself in.
        $result = $this->preflight();

        $this->assertSame(1, $result['code'], 'Preflight passed on a non-production environment.');
    }

    #[DataProvider('unsafeSettings')]
    public function test_it_refuses_each_unsafe_setting(string $key, mixed $value, string $expectInOutput): void
    {
        $this->productionConfig([$key => $value]);

        $result = $this->preflight();

        $this->assertSame(1, $result['code'], "Preflight accepted {$key} = ".var_export($value, true));
        $this->assertStringContainsString($expectInOutput, $result['output']);
    }

    public static function unsafeSettings(): array
    {
        return [
            // `local` is what exposes the passwordless demo logins.
            'APP_ENV local' => ['app.env', 'local', 'APP_ENV'],
            // Prints the resolved config, DB_PASSWORD included, on any error.
            'APP_DEBUG true' => ['app.debug', true, 'APP_DEBUG'],
            // Nothing encrypted can be read, TOTP secrets included.
            'APP_KEY empty' => ['app.key', '', 'APP_KEY'],
            // The reset link is built from this and must be the real address.
            'APP_URL http' => ['app.url', 'http://pos.bbt.edu.pk', 'APP_URL'],
            // Believes any caller's X-Forwarded-For, stepping around the
            // per-IP login brake. This is also the CONFIG DEFAULT, so an
            // absent key lands here.
            'TRUSTED_PROXIES wildcard' => ['app.trusted_proxies', '*', 'TRUSTED_PROXIES'],
            // Freezes every overdue date and this-month figure.
            'INSTITUTE_TODAY pinned' => ['institute.today', '2026-07-15', 'INSTITUTE_TODAY'],
            // A deploy would log out every signed-in officer mid-transaction.
            'SESSION_DRIVER file' => ['session.driver', 'file', 'SESSION_DRIVER'],
            // The session cookie would travel in clear.
            'SESSION_SECURE_COOKIE false' => ['session.secure', false, 'SESSION_SECURE_COOKIE'],
            // The scheduler's backup overlap lock lives in the cache store.
            'CACHE_STORE array' => ['cache.default', 'array', 'CACHE_STORE'],
            // 'sync' runs queued jobs inside the web request.
            'QUEUE_CONNECTION sync' => ['queue.default', 'sync', 'QUEUE_CONNECTION'],
            // THE ONE PEOPLE GET WRONG. keep is a COUNT, and backups are
            // hourly, so 14 retains fourteen HOURS while every check stays
            // green and yesterday quietly disappears.
            'BACKUP_KEEP 14' => ['backup.keep', 14, 'BACKUP_KEEP'],
        ];
    }

    /**
     * The backup path must not sit inside the tree deploy.sh git-resets and
     * chmods — the application's own default does exactly that.
     */
    public function test_it_refuses_a_backup_path_inside_the_application_tree(): void
    {
        $this->productionConfig(['backup.path' => storage_path('app/backups')]);

        $result = $this->preflight();

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('BACKUP_PATH', $result['output']);
    }

    /** SQLite in production would run the counter on a file nothing backs up. */
    public function test_it_refuses_a_non_mysql_default_connection(): void
    {
        $this->productionConfig();

        $result = $this->preflight();

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('DB_CONNECTION', $result['output']);
    }

    /**
     * A missing off-box remote is a WARNING, never a refusal.
     *
     * Refusing over it would block the very first release, which is precisely
     * when the bucket has not been created yet — and a gate that blocks the
     * thing it is meant to protect gets switched off.
     */
    public function test_a_missing_offbox_remote_warns_rather_than_refuses(): void
    {
        $this->productionConfig(['backup.rclone_remote' => '']);

        $result = $this->preflight();

        $this->assertStringContainsString('rclone_remote', $result['output']);
        $this->assertStringContainsString('WARNING', $result['output']);
        // The only failure should be the SQLite one this suite cannot avoid.
        $this->assertStringNotContainsString('backup.keep', $result['output']);
    }
}
