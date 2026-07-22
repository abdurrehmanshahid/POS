<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the platform owner's account.
 *
 * The password is deliberately NOT hardcoded. A committed default credential is
 * the most reliably exploited hole in any self-hosted product, because it
 * survives deployment untouched and is identical on every install. Instead:
 *
 *   - `SUPERADMIN_PASSWORD` in .env is used when present;
 *   - otherwise a strong random password is generated and printed ONCE to the
 *     console during seeding, and never stored anywhere in plaintext.
 *
 * Either way the account starts with no second factor confirmed, so the first
 * visit to /superadmin forces TOTP enrolment before any screen will render.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPERADMIN_EMAIL', 'owner@bbt.edu.pk');
        $username = env('SUPERADMIN_USERNAME', 'superadmin');

        if (SuperAdmin::where('email', $email)->orWhere('username', $username)->exists()) {
            $this->command?->info('Super admin already exists, left untouched.');

            return;
        }

        $password = env('SUPERADMIN_PASSWORD') ?: Str::password(16);

        SuperAdmin::create([
            'name' => env('SUPERADMIN_NAME', 'Platform Owner'),
            'username' => $username,
            'email' => $email,
            'password' => $password, // hashed by the model's 'hashed' cast
            'is_active' => true,
        ]);

        $this->command?->newLine();
        $this->command?->warn('  Super admin created, this is shown once.');
        $this->command?->line('  URL:      /superadmin');
        $this->command?->line("  Username: {$username}");
        $this->command?->line('  Password: '.(env('SUPERADMIN_PASSWORD') ? '(from SUPERADMIN_PASSWORD in .env)' : $password));
        $this->command?->line('  Two-factor enrolment is forced on first sign-in.');
        $this->command?->newLine();
    }
}
