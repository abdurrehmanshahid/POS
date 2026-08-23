<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refuse a production deploy that would land on unsafe configuration.
 *
 * `deploy.sh` already checks most of this against `.env` with shell tools, and
 * that layer is the one that refuses fastest and earliest. This command is the
 * second layer, and it exists because there are two questions the shell cannot
 * answer honestly:
 *
 *   1. **What does Laravel actually resolve?** `.env` is an input, not the
 *      answer. A key can be present and still not mean what it looks like: a
 *      value that fails to parse, a `config/*.php` default that overrides an
 *      empty string, a cast that turns "false" into the string "false". Asking
 *      `config()` asks the thing that will actually serve the requests.
 *
 *   2. **Does the runtime work?** Whether MySQL answers, whether storage is
 *      writable, whether the disk has room. Those are not settings; they are
 *      facts about the machine, and they are the ones that turn a clean deploy
 *      into a 500 on the first page load.
 *
 * `deploy.sh` runs this TWICE:
 *
 *   - in preflight, after `config:clear`, so it reads `.env` — this is the run
 *     that refuses before anything is touched;
 *   - after `config:cache` with `--effective`, so it reads the compiled cache
 *     the application will genuinely serve from, while the site is still in
 *     maintenance mode and a failure is still cheap.
 *
 * Every check reports; the command does not stop at the first failure. One run
 * that lists four wrong settings is one round trip to the server. Four runs
 * that each reveal the next one is four, at midnight.
 */
class DeployPreflight extends Command
{
    protected $signature = 'deploy:preflight
        {--effective : Read the compiled config cache rather than .env, and say so}';

    protected $description = 'Refuse to deploy onto unsafe production configuration or a broken runtime';

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $notes = [];

    public function handle(): int
    {
        $source = $this->option('effective') ? 'compiled config cache' : '.env';
        $this->line("Preflight against the {$source}.");

        $this->checkApplication();
        $this->checkDatabase();
        $this->checkDrivers();
        $this->checkBackups();
        $this->checkStorage();
        $this->checkDisk();

        foreach ($this->notes as $note) {
            $this->line('  · '.$note);
        }

        if ($this->failures === []) {
            $this->info('Preflight passed ('.count($this->notes).' checks).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(count($this->failures).' preflight check(s) failed:');
        foreach ($this->failures as $failure) {
            $this->line('  ✗ '.$failure);
        }
        $this->newLine();
        $this->error('Refusing the deploy. Nothing has been changed.');

        return self::FAILURE;
    }

    // ---- checks ------------------------------------------------------------

    private function checkApplication(): void
    {
        $this->want('APP_ENV', config('app.env') === 'production',
            "app.env is '".config('app.env')."', not 'production'. The demo login buttons and their server-side shortcuts are gated on this value.");

        $this->want('APP_DEBUG', config('app.debug') === false,
            'app.debug is truthy. Any error page would print the resolved configuration, DB_PASSWORD included.');

        $key = (string) config('app.key');
        $this->want('APP_KEY', $key !== '',
            'app.key is empty. Nothing encrypted can be read, which includes every stored TOTP secret.');

        $url = (string) config('app.url');
        $this->want('APP_URL', str_starts_with($url, 'https://'),
            "app.url is '{$url}'. The password-reset link is built from it, so it must be the real https address.");

        // `*` is the config default, so an absent key lands here too. It is
        // correct only where the edge is the sole way in; this box is directly
        // reachable, and a forged X-Forwarded-For would step around the per-IP
        // login brake.
        $proxies = config('app.trusted_proxies');
        $this->want('TRUSTED_PROXIES', ! in_array($proxies, ['*', '**'], true),
            "app.trusted_proxies is '".(is_string($proxies) ? $proxies : json_encode($proxies))."', which believes any caller's X-Forwarded-For header.");

        // Pinning "today" freezes every overdue date and every this-month
        // figure. It exists to reproduce the prototype's demo numbers.
        $today = config('institute.today');
        $this->want('INSTITUTE_TODAY', $today === null || $today === '',
            "institute.today is pinned to '{$today}'. Every overdue date and this-month total would freeze there.");

        // Not asserted as a value, only recorded: the institute decides it, and
        // changing it after the first voucher leaves the series inconsistent.
        $this->notes[] = 'institute.code_prefix = '.config('institute.code_prefix');
    }

    private function checkDatabase(): void
    {
        $default = config('database.default');

        $this->want('DB_CONNECTION', $default === 'mysql',
            "database.default is '{$default}'. The framework default is sqlite, which would run the counter on a file nothing backs up.");

        if ($default !== 'mysql') {
            return;
        }

        $host = config('database.connections.mysql.host');
        $this->want('DB_HOST', $host === '127.0.0.1',
            "database host is '{$host}'. MySQL is on this box and the hourly dump reads the local one.");

        // TIMESTAMP columns are converted by the server's session timezone and
        // DATETIME columns are not. Disagree, and a payment taken near midnight
        // is filed under the wrong day — which is the wrong month's revenue
        // twelve times a year.
        $tz = config('database.connections.mysql.timezone');
        $this->want('DB_TIMEZONE', $tz === '+00:00',
            "database timezone is '{$tz}', not '+00:00'. TIMESTAMP and DATETIME columns would disagree and a payment near midnight would land in the wrong month.");

        // The one check nothing else can make: can we actually talk to it.
        try {
            DB::connection()->select('SELECT 1');
            $version = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $this->notes[] = "MySQL reachable, server version {$version}";
        } catch (Throwable $e) {
            // The exception text carries the host, the username and sometimes
            // the password. It goes to the log, not to the pipeline output.
            logger()->critical('deploy:preflight could not reach the database', ['exception' => $e]);
            $this->failures[] = 'DB unreachable — SELECT 1 failed. Details are in storage/logs, deliberately not here (the driver message carries credentials).';
        }
    }

    private function checkDrivers(): void
    {
        $this->want('SESSION_DRIVER', config('session.driver') === 'database',
            "session.driver is '".config('session.driver')."'. On the file driver every signed-in officer is logged out by a deploy.");

        $this->want('SESSION_SECURE_COOKIE', config('session.secure') === true,
            'session.secure is not true. The session cookie would travel in clear. (Bootstrap order: false for the first deploy, certbot, then true.)');

        // Not cosmetic: the scheduler's withoutOverlapping lock for the hourly
        // backup lives in the cache store. On the array driver it is a no-op
        // per process, so two dumps could run at once.
        $this->want('CACHE_STORE', config('cache.default') === 'database',
            "cache.default is '".config('cache.default')."'. The scheduler's overlap lock lives in the cache store; on 'array' it does not exist.");

        $this->want('QUEUE_CONNECTION', config('queue.default') === 'database',
            "queue.default is '".config('queue.default')."'. There is no Redis on this box and 'sync' runs jobs inside the web request.");
    }

    private function checkBackups(): void
    {
        $path = (string) config('backup.path');

        $this->want('BACKUP_PATH', $path !== '' && ! str_starts_with($path, base_path()),
            "backup.path is '{$path}', which is inside the application tree that deploy.sh git-resets and chmods. Set BACKUP_PATH=/var/backups/institute.");

        if ($path !== '') {
            $this->want('BACKUP_PATH writable', is_dir($path) && is_writable($path),
                "backup.path '{$path}' does not exist or is not writable by ".get_current_user().'. The mandatory pre-deploy dump would fail.');
        }

        // Prunes by COUNT, not age. Harmless at one dump a night; at one an
        // hour, 14 means fourteen hours of history while every check stays
        // green and yesterday quietly disappears.
        $keep = (int) config('backup.keep');
        $this->want('BACKUP_KEEP', $keep >= 168,
            "backup.keep is {$keep}. Backups are hourly, and keep is a COUNT — {$keep} retains roughly {$keep} hours, not {$keep} days. Production wants 168 (seven days).");

        $remote = (string) config('backup.rclone_remote');
        if ($remote === '') {
            // A warning, not a failure. A box with no off-box copy is a box
            // with a backup and no disaster recovery, but refusing the deploy
            // over it would block the very first release, which is when the
            // remote has not been configured yet.
            $this->notes[] = 'WARNING: backup.rclone_remote is empty — dumps are NOT copied off the box. A dump on the same disk as the database dies with the disk.';
        } else {
            $this->notes[] = "Off-box backup remote: {$remote}";
        }
    }

    private function checkStorage(): void
    {
        // The paths Laravel writes to on a normal request. Any one of them
        // read-only is a 500 on a page that looks unrelated to storage.
        $paths = [
            storage_path('framework/views'),
            storage_path('framework/cache'),
            storage_path('logs'),
            storage_path('app'),
            base_path('bootstrap/cache'),
        ];

        foreach ($paths as $path) {
            $this->want('writable '.str_replace(base_path().'/', '', $path),
                is_dir($path) && is_writable($path),
                "{$path} is missing or not writable by ".get_current_user().'.');
        }
    }

    private function checkDisk(): void
    {
        $free = @disk_free_space(base_path());

        if ($free === false) {
            $this->notes[] = 'Could not read free disk space.';

            return;
        }

        $freeMb = (int) round($free / 1048576);

        $this->want('disk', $freeMb >= 2048,
            "only {$freeMb}MB free. The pre-deploy dump, composer's cache and the new build all need room, and a full disk looks like a hundred unrelated bugs.");

        $this->notes[] = "Free disk: {$freeMb}MB";
    }

    // ---- plumbing ----------------------------------------------------------

    private function want(string $label, bool $ok, string $failure): void
    {
        if ($ok) {
            $this->notes[] = $label.' ok';

            return;
        }

        $this->failures[] = $label.' — '.$failure;
    }
}
