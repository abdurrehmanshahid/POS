<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StepUp;
use App\Services\TwoFactor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

/** TOTP enrolment, challenge, replay protection and step-up confirmation. */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('username', 'adminansar')->firstOrFail();
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    /** The current valid code for a secret, computed the same way the app does. */
    private function codeFor(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    public function test_admin_role_requires_two_factor_and_officer_does_not(): void
    {
        $this->assertTrue($this->admin()->requiresTwoFactor());
        $this->assertFalse($this->officer()->requiresTwoFactor());
    }

    public function test_unenrolled_admin_is_pinned_to_setup_screen(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertRedirect(route('two-factor.setup'));
    }

    public function test_officer_without_two_factor_reaches_the_app_normally(): void
    {
        $this->actingAs($this->officer())->get('/dashboard')->assertOk();
    }

    public function test_enrolment_requires_a_valid_code_and_only_then_stores_the_secret(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)
            ->test('pages.auth.two-factor-setup', ['guard' => 'web']);

        $secret = $component->get('secret');
        $this->assertNotEmpty($secret);

        // A wrong code must not commit anything.
        $component->set('code', '000000')->call('confirm');
        $this->assertNull($admin->fresh()->two_factor_secret, 'Secret must not be stored on a failed confirm.');

        // The right code enrols and surfaces recovery codes exactly once.
        $component->set('code', $this->codeFor($secret))->call('confirm');

        $admin->refresh();
        $this->assertTrue($admin->hasTwoFactorEnabled());
        $this->assertSame($secret, $admin->two_factor_secret);
        $this->assertCount(8, $component->get('recoveryCodes'));
        $this->assertCount(8, $admin->two_factor_recovery_codes);
    }

    public function test_recovery_codes_are_hashed_not_stored_in_plaintext(): void
    {
        $admin = $this->admin();
        $component = Livewire::actingAs($admin)->test('pages.auth.two-factor-setup', ['guard' => 'web']);
        $secret = $component->get('secret');
        $component->set('code', $this->codeFor($secret))->call('confirm');

        $plain = $component->get('recoveryCodes');
        $stored = $admin->fresh()->two_factor_recovery_codes;

        foreach ($plain as $code) {
            $this->assertNotContains($code, $stored, 'Recovery codes must never be stored in plaintext.');
        }
    }

    public function test_password_alone_does_not_create_a_session_when_two_factor_is_on(): void
    {
        $admin = $this->enrolTwoFactor($this->admin());

        Livewire::test('pages.auth.login')
            ->set('user', 'adminansar')
            ->set('password', 'Bbt@Admin1')
            ->call('login')
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest('web');
    }

    public function test_valid_code_completes_sign_in(): void
    {
        $secret = app(TwoFactor::class)->generateSecret();
        $admin = $this->enrolTwoFactor($this->admin(), $secret);

        Livewire::test('pages.auth.login')
            ->set('user', 'adminansar')->set('password', 'Bbt@Admin1')->call('login');

        Livewire::test('pages.auth.two-factor-challenge')
            ->set('code', $this->codeFor($secret))
            ->call('verify');

        $this->assertAuthenticatedAs($admin->fresh(), 'web');
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $secret = app(TwoFactor::class)->generateSecret();
        $admin = $this->enrolTwoFactor($this->admin(), $secret);
        $totp = app(TwoFactor::class);
        $code = $this->codeFor($secret);

        $this->assertTrue($totp->verify($admin, $code), 'First use should succeed.');
        $this->assertFalse(
            $totp->verify($admin->fresh(), $code),
            'The same code must not be accepted twice inside its window.'
        );
    }

    public function test_step_up_demands_a_fresh_code_for_enrolled_actors(): void
    {
        $secret = app(TwoFactor::class)->generateSecret();
        $admin = $this->enrolTwoFactor($this->admin(), $secret);
        $stepUp = app(StepUp::class);

        $this->assertTrue($stepUp->usesTotp($admin));

        $code = $this->codeFor($secret);
        $stepUp->confirm($admin, $code); // first destructive action: fine

        // A second destructive action cannot reuse it.
        $this->expectException(RuntimeException::class);
        $stepUp->confirm($admin->fresh(), $code);
    }

    public function test_step_up_falls_back_to_password_for_unenrolled_actors(): void
    {
        $officer = $this->officer();
        $stepUp = app(StepUp::class);

        $this->assertFalse($stepUp->usesTotp($officer));
        $stepUp->confirm($officer, 'Bbt@Officer1');

        $this->expectException(RuntimeException::class);
        $stepUp->confirm($officer, 'wrong-password');
    }

    public function test_recovery_code_signs_in_and_is_single_use(): void
    {
        $admin = $this->admin();
        $component = Livewire::actingAs($admin)->test('pages.auth.two-factor-setup', ['guard' => 'web']);
        $secret = $component->get('secret');
        $component->set('code', $this->codeFor($secret))->call('confirm');
        $recovery = $component->get('recoveryCodes')[0];

        $admin->refresh();
        $this->assertTrue($admin->consumeRecoveryCode($recovery));
        $this->assertFalse(
            $admin->fresh()->consumeRecoveryCode($recovery),
            'A recovery code must work only once.'
        );
        $this->assertSame(7, $admin->fresh()->recoveryCodesRemaining());
    }
}
