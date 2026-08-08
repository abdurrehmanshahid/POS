<?php

use App\Services\Audit;
use App\Services\TwoFactorChallenge;
use App\Support\Nav;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The second factor, demanded BEFORE a session exists.
 *
 * Reaching this screen means a password was accepted but no session was
 * granted, {@see \App\Services\TwoFactorChallenge} holds the identity as a
 * pending intent only. Walking away here leaves the attacker with nothing.
 *
 * Rate-limited in its own right: a six-digit code is only a million
 * possibilities, which is trivially brute-forced if you may guess freely. Ten
 * attempts and the handshake is torn down and the password must be re-entered.
 */
new #[Layout('components.layouts.guest')] class extends Component {
    public string $code = '';

    public string $error = '';

    public bool $useRecovery = false;

    public function mount()
    {
        // No pending handshake → nothing to challenge. Send them back rather
        // than rendering a form that can never succeed.
        if (! app(TwoFactorChallenge::class)->hasPending()) {
            return $this->redirect(route('login'), navigate: true);
        }
    }

    private function throttleKey(): string
    {
        return '2fa:'.sha1(session()->getId());
    }

    public function verify()
    {
        $this->error = '';
        $challenge = app(TwoFactorChallenge::class);
        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $challenge->abandon();
            RateLimiter::clear($key);

            return $this->redirect(route('login'), navigate: true);
        }

        $user = $challenge->pendingUser();

        if (! $user) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (! $challenge->attempt($this->code)) {
            RateLimiter::hit($key, 300);
            $this->error = $this->useRecovery
                ? 'That recovery code is not valid or has already been used.'
                : 'That code is not valid. Wait for the next one and try again.';
            $this->code = '';

            Audit::record('Two-factor failed', null, [
                'subject' => $user,
                'subject_label' => $user->name,
            ]);

            return null;
        }

        RateLimiter::clear($key);
        $guard = auth()->guard('superadmin')->check() ? 'superadmin' : 'web';
        $signedIn = auth()->guard($guard)->user();

        $signedIn->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->save();

        Audit::record('Signed in', $signedIn, [
            'subject' => $signedIn,
            'subject_label' => $signedIn->name,
            'context' => ['second_factor' => $this->useRecovery ? 'recovery code' : 'totp'],
        ]);

        // Burning a recovery code is worth flagging, it means someone has lost
        // a phone, or someone else is using codes they should not have.
        if ($this->useRecovery) {
            Audit::record('Recovery code used', $signedIn, [
                'subject' => $signedIn,
                'subject_label' => $signedIn->name,
                'context' => ['remaining' => $signedIn->recoveryCodesRemaining()],
            ]);
        }

        if ($guard === 'superadmin') {
            return $this->redirect(route('superadmin.dashboard'), navigate: true);
        }

        if ($signedIn->must_reset_password) {
            return $this->redirect(route('password.set'), navigate: true);
        }

        session()->flash('toast', ['tone' => 'ok', 'title' => 'Signed in', 'msg' => 'Welcome back, '.$signedIn->name]);

        return $this->redirect(route(Nav::firstScreen($signedIn)), navigate: true);
    }

    public function cancel()
    {
        app(TwoFactorChallenge::class)->abandon();

        return $this->redirect(route('login'), navigate: true);
    }
}; ?>

<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px;background:var(--bg)">
    <div style="width:100%;max-width:420px">
        <div class="card" style="padding:32px 30px">
            <div style="display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:var(--iris-bg);margin-bottom:16px">
                <x-icon name="shield" :size="21" style="color:var(--iris)" />
            </div>

            <h2 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);margin:0 0 6px;letter-spacing:-.01em">Two-factor required</h2>
            <p style="font-size:var(--fs-base);color:var(--muted);margin:0 0 22px;line-height:1.6">
                @if ($useRecovery)
                    Enter one of the recovery codes you saved when you set up two-factor.
                @else
                    Open your authenticator app and enter the current 6-digit code for this account.
                @endif
            </p>

            <form wire:submit="verify">
                @if ($useRecovery)
                    <label class="label">Recovery code</label>
                    <input wire:model="code" type="text" placeholder="XXXXX-XXXXX" class="input tnum"
                           style="margin-bottom:12px;font-size:var(--fs-md);letter-spacing:.1em;text-align:center;font-weight:700" autofocus>
                @else
                    <label class="label">Authenticator code</label>
                    <input wire:model="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                           placeholder="000000" class="input tnum"
                           style="margin-bottom:12px;font-size:var(--fs-xl);letter-spacing:.35em;text-align:center;font-weight:700" autofocus>
                @endif

                @if ($error)
                    <div style="display:flex;align-items:center;gap:7px;font-size:var(--fs-xs);color:var(--over);margin-bottom:14px">
                        <x-icon name="alert-circle" :size="14" /> {{ $error }}
                    </div>
                @endif

                <button type="submit" class="btn btn-accent" style="width:100%;height:48px;font-size:var(--fs-md);margin-bottom:14px">Verify &amp; sign in</button>
            </form>

            <div style="display:flex;align-items:center;justify-content:space-between;font-size:var(--fs-xs)">
                <button wire:click="$toggle('useRecovery')" type="button"
                        style="border:none;background:none;padding:0;cursor:pointer;color:var(--iris);font-weight:600;font-size:var(--fs-xs)">
                    {{ $useRecovery ? 'Use authenticator code' : 'Lost your phone?' }}
                </button>
                <button wire:click="cancel" type="button"
                        style="border:none;background:none;padding:0;cursor:pointer;color:var(--muted);font-weight:600;font-size:var(--fs-xs)">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>
