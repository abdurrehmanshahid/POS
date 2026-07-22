<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression tests for the authentication holes found in the prototype port.
 * Each test names the hole it keeps shut, if one of these ever fails, a real
 * vulnerability has been reintroduced.
 */
class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    // ---- Hole 1: account takeover via forgot-password ----------------------

    public function test_forgot_password_cannot_change_a_password(): void
    {
        $officer = $this->officer();
        $before = $officer->password;

        // Self-service reset is off by default, so the action does not exist.
        Livewire::test('pages.auth.forgot')
            ->set('user', 'aliraza')
            ->call('submit')
            ->assertStatus(404);

        $this->assertSame($before, $officer->fresh()->password, 'Password must be untouched.');
        $this->assertTrue(Hash::check('Bbt@Officer1', $officer->fresh()->password));
    }

    public function test_forgot_password_screen_offers_no_reset_form_by_default(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('reset by an administrator')
            ->assertDontSee('Send reset link');
    }

    public function test_enabled_self_service_reset_does_not_reveal_whether_an_account_exists(): void
    {
        config(['institute.self_service_reset' => true]);

        $real = Livewire::test('pages.auth.forgot')->set('user', 'aliraza')->call('submit')->get('sent');
        $fake = Livewire::test('pages.auth.forgot')->set('user', 'nobody-here')->call('submit')->get('sent');

        $this->assertSame($real, $fake, 'The reply must not differ between real and unknown accounts.');
        $this->assertNotEmpty($real);
    }

    // ---- Hole 2: demo credentials exposed in production --------------------

    public function test_demo_credential_fill_is_rejected_outside_local(): void
    {
        // The test environment is not 'local', which is what production is like.
        Livewire::test('pages.auth.login')->call('fillAdmin')->assertStatus(404);
        Livewire::test('pages.auth.login')->call('fillOfficer')->assertStatus(404);
    }

    public function test_login_page_does_not_print_real_passwords_outside_local(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Bbt@Admin1')
            ->assertDontSee('Bbt@Officer1');
    }

    // ---- Hole 3: throttling defeated by rotating IPs -----------------------

    public function test_lockout_follows_the_account_across_different_ips(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Livewire::test('pages.auth.login')
                ->set('user', 'aliraza')
                ->set('password', 'wrong-'.$i)
                ->call('login');
        }

        // A brand new origin must still find the account locked.
        $this->app['request']->server->set('REMOTE_ADDR', '203.0.113.99');

        $component = Livewire::test('pages.auth.login')
            ->set('user', 'aliraza')
            ->set('password', 'Bbt@Officer1')
            ->call('login');

        $this->assertStringContainsString('locked', $component->get('error'));
        $this->assertGuest('web');
    }

    public function test_failed_sign_in_is_recorded_without_the_password(): void
    {
        Livewire::test('pages.auth.login')
            ->set('user', 'aliraza')
            ->set('password', 'hunter2')
            ->call('login');

        $row = AuditLog::where('action', 'Sign-in failed')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('hunter2', json_encode($row->context));
    }

    // ---- Hole 4: deactivation did not end a live session -------------------

    public function test_deactivating_a_user_ends_their_existing_session(): void
    {
        $officer = $this->officer();

        $this->actingAs($officer)->get('/dashboard')->assertOk();

        $officer->forceFill(['is_active' => false])->save();

        $this->actingAs($officer->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_soft_deleted_user_cannot_sign_in(): void
    {
        $officer = $this->officer();
        $officer->delete();

        $component = Livewire::test('pages.auth.login')
            ->set('user', 'aliraza')
            ->set('password', 'Bbt@Officer1')
            ->call('login');

        $this->assertNotEmpty($component->get('error'));
        $this->assertGuest('web');
    }

    // ---- Hole 5: self-registration route ------------------------------------

    public function test_there_is_no_public_registration_route(): void
    {
        $this->get('/register')->assertNotFound();
    }

    // ---- Audit integrity -----------------------------------------------------

    public function test_audit_rows_cannot_be_edited_or_deleted(): void
    {
        $this->actingAs($this->officer())->get('/dashboard');
        $row = AuditLog::firstOrFail();

        $this->expectException(\RuntimeException::class);
        $row->update(['action' => 'Nothing happened']);
    }

    public function test_audit_rows_cannot_be_deleted(): void
    {
        $row = AuditLog::firstOrFail();

        $this->expectException(\RuntimeException::class);
        $row->delete();
    }

    public function test_successful_sign_in_is_audited_and_stamps_last_login(): void
    {
        Livewire::test('pages.auth.login')
            ->set('user', 'aliraza')
            ->set('password', 'Bbt@Officer1')
            ->call('login');

        $officer = $this->officer()->fresh();

        $this->assertNotNull($officer->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Signed in',
            'actor_type' => 'user',
            'actor_id' => $officer->id,
        ]);
    }
}
