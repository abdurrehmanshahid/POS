<?php

use App\Services\Audit;
use App\Services\TwoFactor;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * TOTP enrolment, shared by both guards.
 *
 * The ordering here is the whole point: the secret is held in the component
 * (server-side, per-session) and is only WRITTEN to the account once the user
 * has typed a code it generates. Committing the secret first would let a
 * mis-scan or a closed tab leave an account permanently requiring codes from an
 * app that never actually received the secret, a self-inflicted lockout with
 * no way back except a DB edit.
 *
 * Recovery codes are shown exactly once, immediately after confirmation. They
 * are stored hashed (see App\Services\TwoFactor), so this screen is genuinely
 * the only chance to record them.
 */
new #[Layout('components.layouts.guest')] class extends Component {
    public string $guard = 'web';

    public string $secret = '';

    public string $code = '';

    public string $error = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public bool $done = false;

    public function mount(string $guard = 'web'): void
    {
        $this->guard = $guard;
        abort_unless(auth()->guard($guard)->check(), 403);

        $user = auth()->guard($guard)->user();

        // Re-enrolment is allowed (rotating a factor after losing a phone), but
        // only from the account's own authenticated session.
        $this->secret = app(TwoFactor::class)->generateSecret();
    }

    public function with(): array
    {
        $user = auth()->guard($this->guard)->user();

        return [
            'user' => $user,
            'qr' => app(TwoFactor::class)->qrCodeDataUri($user->email, $this->secret),
            'alreadyEnrolled' => $user->hasTwoFactorEnabled(),
        ];
    }

    public function confirm(): void
    {
        $this->error = '';
        $totp = app(TwoFactor::class);
        $user = auth()->guard($this->guard)->user();

        // Verified against the PENDING secret, not the stored one, nothing has
        // been committed to the account yet.
        if (! $totp->verifyPendingSecret($this->secret, $this->code)) {
            $this->error = 'That code is not right. Check your phone\'s clock and try the current code.';
            $this->code = '';

            return;
        }

        $codes = $totp->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $this->secret,
            'two_factor_recovery_codes' => $codes['hashed'],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_timestep' => null,
        ])->save();

        Audit::record('Two-factor enabled', $user, [
            'subject' => $user,
            'subject_label' => $user->name,
            'field' => 'two_factor_confirmed_at',
            'old_value' => 'disabled',
            'new_value' => 'enabled',
        ]);

        $this->recoveryCodes = $codes['plain'];
        $this->done = true;
        // Drop the secret from component state now that it is committed.
        $this->secret = '';
        $this->code = '';
    }

    public function finish()
    {
        $home = $this->guard === 'superadmin' ? 'superadmin.dashboard' : 'dashboard';

        session()->flash('toast', [
            'tone' => 'ok',
            'title' => 'Two-factor enabled',
            'msg' => 'Your account now requires a code at sign-in',
        ]);

        return $this->redirect(route($home), navigate: true);
    }
}; ?>

<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px;background:var(--bg)">
    <div style="width:100%;max-width:520px">
        <div class="card" style="padding:32px 30px">

            @if (! $done)
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px">
                    <div style="display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:var(--iris-bg);flex:none">
                        <x-icon name="shield" :size="21" style="color:var(--iris)" />
                    </div>
                    <div>
                        <h2 style="font-size:21px;font-weight:800;color:var(--ink);margin:0;letter-spacing:-.01em">Set up two-factor</h2>
                        <p style="font-size:13px;color:var(--muted);margin:2px 0 0">Required for the {{ $user->role?->name ?? 'super admin' }} role</p>
                    </div>
                </div>

                <ol style="margin:0 0 20px;padding-left:20px;font-size:13.5px;color:var(--ink2);line-height:1.9">
                    <li>Install <strong>Google Authenticator</strong> (or any TOTP app. Authy, 1Password).</li>
                    <li>Scan this QR code, or type the setup key by hand.</li>
                    <li>Enter the 6-digit code it shows to confirm.</li>
                </ol>

                <div style="display:flex;gap:20px;align-items:center;padding:18px;background:var(--surface2);border:1px solid var(--border);border-radius:14px;margin-bottom:20px;flex-wrap:wrap">
                    <img src="{{ $qr }}" alt="Two-factor QR code" width="150" height="150" style="border-radius:10px;background:#fff;padding:8px;flex:none">
                    <div style="flex:1;min-width:180px">
                        <div style="font-size:11.5px;font-weight:700;color:var(--faint);letter-spacing:.06em;text-transform:uppercase;margin-bottom:7px">Setup key</div>
                        <div class="tnum" style="font-size:13px;font-weight:700;color:var(--ink);word-break:break-all;line-height:1.6;user-select:all">{{ $secret }}</div>
                        <div style="font-size:11.5px;color:var(--muted);margin-top:9px;line-height:1.5">Use this if you can't scan. Account: {{ $user->email }}</div>
                    </div>
                </div>

                <form wire:submit="confirm">
                    <label class="label">6-digit code from your app</label>
                    <input wire:model="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                           placeholder="000000" class="input tnum"
                           style="margin-bottom:12px;font-size:22px;letter-spacing:.35em;text-align:center;font-weight:700" autofocus>

                    @if ($error)
                        <div style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--over);margin-bottom:14px">
                            <x-icon name="alert-circle" :size="14" /> {{ $error }}
                        </div>
                    @endif

                    <button type="submit" class="btn btn-accent" style="width:100%;height:48px;font-size:15px">Confirm &amp; enable</button>
                </form>

                {{-- The way out.

                     EnsureTwoFactorEnrolled deliberately allows `logout` from
                     this screen, but nothing here reached it, so anyone pinned
                     to enrolment (every Administrator, since that role requires
                     a second factor) had no way off it but clearing cookies.
                     Signing out without enrolling is a legitimate thing to want:
                     the phone is at home. --}}
                <form method="POST" action="{{ route($guard === 'superadmin' ? 'superadmin.logout' : 'logout') }}"
                      style="margin-top:14px;text-align:center">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm">
                        <x-icon name="logout" :size="14" /> Sign out instead
                    </button>
                </form>
            @else
                {{-- One-time display of recovery codes. --}}
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                    <div style="display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:var(--paid-bg);flex:none">
                        <x-icon name="shield-check" :size="21" style="color:var(--paid)" />
                    </div>
                    <div>
                        <h2 style="font-size:21px;font-weight:800;color:var(--ink);margin:0;letter-spacing:-.01em">Two-factor is on</h2>
                        <p style="font-size:13px;color:var(--muted);margin:2px 0 0">Save your recovery codes now</p>
                    </div>
                </div>

                <div style="display:flex;gap:9px;padding:12px 13px;background:var(--over-bg);border:1px solid var(--over-br);border-radius:11px;margin-bottom:16px">
                    <x-icon name="alert-circle" :size="16" style="color:var(--over);flex:none;margin-top:1px" />
                    <span style="font-size:12.5px;color:var(--over);font-weight:600;line-height:1.55">
                        These are shown once and cannot be retrieved later. Each works a single time if you lose your phone. Print them or put them in a password manager.
                    </span>
                </div>

                <div class="tnum" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:16px;background:var(--surface2);border:1px solid var(--border);border-radius:12px;margin-bottom:20px">
                    @foreach ($recoveryCodes as $rc)
                        <div style="font-size:14px;font-weight:700;color:var(--ink);letter-spacing:.04em;user-select:all">{{ $rc }}</div>
                    @endforeach
                </div>

                <button wire:click="finish" class="btn btn-accent" style="width:100%;height:48px;font-size:15px">
                    I've saved them, continue
                </button>
            @endif
        </div>
    </div>
</div>
