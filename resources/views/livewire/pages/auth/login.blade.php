<?php

use App\Models\User;
use App\Services\Audit;
use App\Services\TwoFactorChallenge;
use App\Support\LoginThrottle;
use App\Support\Nav;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Staff sign-in (spec §5.1).
 *
 * Password acceptance does NOT by itself create a session. If the account holds
 * a confirmed second factor the identity is parked as a pending challenge and
 * the session is only granted once a valid code arrives, see
 * {@see \App\Services\TwoFactorChallenge} for why that ordering matters.
 */
new #[Layout('components.layouts.guest')] class extends Component {
    /** Valid bcrypt digest that no supplied password can match, see login(). */
    private const DUMMY_HASH = '$2y$12$Usi/Ae/dK.T3FMwZZ.mchuGkkmHk7NxUas/YD6acHvUQJ.5Nax7ae';

    public string $user = '';
    public string $password = '';
    public bool $remember = true;
    public string $error = '';

    /**
     * Demo credential helpers. Gated to the local environment because the
     * prototype shipped real seeded passwords rendered into the page and
     * callable from the client, convenient for a demo, an open door in
     * production. `app()->environment('local')` is checked on the SERVER here,
     * not merely in the Blade template, so hiding the buttons is not the only
     * thing standing between an attacker and an admin session.
     */
    public function fillAdmin(): void
    {
        abort_unless(app()->environment('local'), 404);
        $this->user = 'adminansar';
        $this->password = 'Bbt@Admin1';
    }

    public function fillOfficer(): void
    {
        abort_unless(app()->environment('local'), 404);
        $this->user = 'aliraza';
        $this->password = 'Bbt@Officer1';
    }

    public function login()
    {
        $this->error = '';
        $throttle = LoginThrottle::for($this->user, request()->ip());

        // Failed-attempt lockout (spec §5.1), now keyed on account AND origin.
        if ($throttle->isLocked()) {
            $this->error = $throttle->lockedMessage();

            return null;
        }

        $v = Str::lower(trim($this->user));
        $u = User::query()
            ->where(fn ($q) => $q->whereRaw('LOWER(username) = ?', [$v])->orWhereRaw('LOWER(email) = ?', [$v]))
            ->first();

        // Always spend the cost of one bcrypt comparison, even when no account
        // matched. Skipping it would make "user not found" measurably faster
        // than "wrong password", turning response time into an
        // account-enumeration oracle. The constant below is a valid 60-char
        // bcrypt digest of a string nobody will ever type, so it always fails.
        $passwordOk = $u
            ? Hash::check($this->password, $u->password)
            : Hash::check($this->password, self::DUMMY_HASH);

        if (! $u || ! $u->is_active || ! $passwordOk) {
            $attempts = $throttle->recordFailure();
            $this->error = $throttle->failureMessage($attempts);

            // Recorded so a spray or stuffing run is visible in the activity
            // feed. The typed identifier is kept; the password never is.
            Audit::record('Sign-in failed', null, [
                'subject' => $u,
                'subject_label' => $u?->name ?? Str::limit($v, 60),
                'context' => ['identifier' => Str::limit($v, 60), 'attempt' => $attempts],
            ]);

            return null;
        }

        $throttle->clear();

        // Second factor confirmed → withhold the session until a code arrives.
        if ($u->hasTwoFactorEnabled()) {
            app(TwoFactorChallenge::class)->start($u, 'web', $this->remember);

            return $this->redirect(route('two-factor.challenge'), navigate: false);
        }

        return $this->completeSignIn($u);
    }

    /**
     * Grant the session. Reached either directly (no second factor required) or
     * from the challenge screen once a code has been verified.
     */
    private function completeSignIn(User $u)
    {
        Auth::login($u, $this->remember);
        // Session fixation defence: a brand new session ID on privilege change,
        // so a pre-set cookie cannot be ridden into an authenticated session.
        // Resolved via the facade rather than request()->session() because the
        // latter is not always bound in a Livewire request context.
        session()->regenerate();

        $u->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->save();

        Audit::record('Signed in', $u, ['subject' => $u, 'subject_label' => $u->name]);

        if ($u->must_reset_password) {
            return $this->redirect(route('password.set'), navigate: true);
        }

        // A role that mandates TOTP but has none enrolled lands on the setup
        // screen; EnsureTwoFactorEnrolled pins them there until it is done.
        if ($u->mustEnrolTwoFactor()) {
            return $this->redirect(route('two-factor.setup'), navigate: true);
        }

        session()->flash('toast', ['tone' => 'ok', 'title' => 'Signed in', 'msg' => 'Welcome back, '.$u->name]);

        return $this->redirect(route(Nav::firstScreen($u)), navigate: true);
    }
}; ?>

<div style="min-height:100vh;display:grid;grid-template-columns:1.05fr .95fr;background:var(--navy-deep)">
    {{-- Marketing panel --}}
    <div style="position:relative;display:flex;flex-direction:column;justify-content:space-between;padding:48px 56px;background:radial-gradient(1200px 600px at 15% -10%,#3a357f 0%,transparent 55%),radial-gradient(900px 500px at 110% 120%,rgba(247,148,30,.22) 0%,transparent 50%),var(--navy);overflow:hidden"
         class="login-hero">
        <div style="display:flex;align-items:center;gap:14px">
            <img src="{{ asset('assets/bbt-logo-white.png') }}" alt="Big Binary Tech" style="height:52px;display:block" onerror="this.outerHTML='<span style=&quot;color:#fff;font-weight:800;font-size:22px&quot;>Big Binary Tech</span>'">
        </div>
        <div style="position:relative;z-index:2;max-width:460px">
            <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:rgba(247,148,30,.16);border:1px solid rgba(247,148,30,.3);color:var(--orange2);font-size:12.5px;font-weight:600;letter-spacing:.02em;margin-bottom:22px">Institute Management System</div>
            <h1 style="font-size:38px;line-height:1.15;font-weight:800;color:#fff;margin:0 0 16px;letter-spacing:-.02em">Registrations, fees &amp; access -<span style="color:var(--orange2)"> accountable by design.</span></h1>
            <p style="font-size:15.5px;line-height:1.65;color:#b9bcdd;margin:0">Every action is scoped by permission and signed under the officer's name. Admins grant exactly what each role needs, nothing hardcoded, no loopholes.</p>
            <div style="display:flex;gap:26px;margin-top:34px">
                <div><div style="font-size:24px;font-weight:800;color:#fff" class="tnum">2,000+</div><div style="font-size:12.5px;color:#9599c4;margin-top:2px">Managed users</div></div>
                <div style="width:1px;background:rgba(255,255,255,.12)"></div>
                <div><div style="font-size:24px;font-weight:800;color:#fff" class="tnum">44</div><div style="font-size:12.5px;color:#9599c4;margin-top:2px">Active courses</div></div>
                <div style="width:1px;background:rgba(255,255,255,.12)"></div>
                <div><div style="font-size:24px;font-weight:800;color:#fff">100%</div><div style="font-size:12.5px;color:#9599c4;margin-top:2px">Audited money</div></div>
            </div>
        </div>
        <div style="position:relative;z-index:2;font-size:12.5px;color:#7f83b0">© 2026 Big Binary Tech Institute · Secure staff portal</div>
    </div>

    {{-- Form panel --}}
    <div style="display:flex;align-items:center;justify-content:center;padding:40px;background:var(--bg)">
        <div style="width:100%;max-width:410px" x-data="{ show: false }">
            <h2 style="font-size:25px;font-weight:800;color:var(--ink);margin:0 0 6px;letter-spacing:-.01em">Sign in</h2>
            <p style="font-size:14px;color:var(--muted);margin:0 0 24px">Enter your credentials to continue to the portal.</p>

            @if ($error)
                <div style="display:flex;align-items:center;gap:9px;padding:11px 13px;background:var(--over-bg);border:1px solid var(--over-br);border-radius:11px;margin-bottom:16px">
                    <x-icon name="alert-circle" :size="16" style="color:var(--over)" />
                    <span style="font-size:12.5px;color:var(--over);font-weight:600">{{ $error }}</span>
                </div>
            @endif

            <form wire:submit="login">
                <label class="label">Email or username</label>
                <input wire:model="user" type="text" placeholder="adminansar" class="input" style="margin-bottom:16px" autofocus>

                <label class="label">Password</label>
                <div style="position:relative;margin-bottom:16px">
                    <input wire:model="password" :type="show ? 'text' : 'password'" placeholder="Enter password" class="input" style="padding-right:74px">
                    <button @click="show = !show" type="button" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);height:32px;padding:0 11px;border:none;background:var(--surface3);color:var(--ink2);border-radius:8px;font-size:12px;font-weight:700;cursor:pointer" x-text="show ? 'Hide' : 'Show'"></button>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink2);cursor:pointer"><input wire:model="remember" type="checkbox" style="width:16px;height:16px;accent-color:var(--iris)">Keep me signed in</label>
                    <a href="{{ route('password.request') }}" wire:navigate style="font-size:13px;font-weight:600">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;height:48px;font-size:15px;margin-bottom:18px">
                    <x-icon name="signin" :size="18" /> Sign in
                </button>
            </form>

            {{-- Local-development convenience only. Never rendered outside the
                 local environment, and the fill actions abort server-side too. --}}
            @if (app()->environment('local'))
            <div style="padding:14px 16px;border:1px dashed var(--border2);border-radius:12px;background:var(--surface2);margin-bottom:14px">
                <div style="font-size:11.5px;font-weight:700;color:var(--faint);letter-spacing:.06em;text-transform:uppercase;margin-bottom:10px">Local dev · demo accounts (click to fill)</div>
                <div style="display:flex;flex-direction:column;gap:8px">
                    <button wire:click="fillAdmin" style="display:flex;align-items:center;gap:10px;width:100%;height:44px;padding:0 12px;border:1.5px solid var(--border2);background:var(--surface);border-radius:10px;cursor:pointer;text-align:left">
                        <span class="avatar avatar-navy" style="width:28px;height:28px;font-size:11px">AA</span>
                        <span style="flex:1"><span style="display:block;font-size:12.5px;font-weight:700;color:var(--ink)">Administrator</span><span class="tnum" style="display:block;font-size:11px;color:var(--muted)">adminansar · Bbt@Admin1</span></span>
                    </button>
                    <button wire:click="fillOfficer" style="display:flex;align-items:center;gap:10px;width:100%;height:44px;padding:0 12px;border:1.5px solid var(--border2);background:var(--surface);border-radius:10px;cursor:pointer;text-align:left">
                        <span class="avatar avatar-orange" style="width:28px;height:28px;font-size:11px">AR</span>
                        <span style="flex:1"><span style="display:block;font-size:12.5px;font-weight:700;color:var(--ink)">Admission Officer</span><span class="tnum" style="display:block;font-size:11px;color:var(--muted)">aliraza · Bbt@Officer1</span></span>
                    </button>
                </div>
            </div>
            @endif

            <div style="display:flex;align-items:center;gap:8px;justify-content:center;font-size:12px;color:var(--faint)">
                <x-icon name="lock" :size="13" /> 256-bit TLS · role &amp; permission scoped
            </div>
        </div>
    </div>
</div>
