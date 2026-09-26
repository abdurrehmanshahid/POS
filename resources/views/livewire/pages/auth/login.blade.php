<?php

use App\Models\User;
use App\Services\Audit;
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
 * A correct password grants the session straight away; there is no second factor.
 */
new #[Layout('components.layouts.guest')] class extends Component {
    /** Valid bcrypt digest that no supplied password can match, see login(). */
    private const DUMMY_HASH = '$2y$12$Usi/Ae/dK.T3FMwZZ.mchuGkkmHk7NxUas/YD6acHvUQJ.5Nax7ae';

    public string $user = '';
    public string $password = '';
    /**
     * Off by default.
     *
     * This is a staff portal worked from shared counter machines, and a
     * remember-me cookie outlives the session by design. Opting every user into
     * that silently sits badly beside the rest of the access model, which treats
     * a persistent token as consequential enough to clear on removal. One click
     * for the minority who want it on their own machine.
     */
    public bool $remember = false;
    public string $error = '';

    // The click-to-fill demo credential helpers were removed outright. They were
    // gated to the local environment on the server, so production was never
    // exposed by them — but a login screen that can print a working password at
    // all is one misread config away from printing it to everyone, and no
    // gate is safer than a correct gate. Credentials for a local database now
    // come from whoever seeded it.

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

        if (! $u || ! $passwordOk) {
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

        // Correct credential, switched-off account. This used to be folded into
        // the branch above and answered "Incorrect username or password", which
        // is a lie the screen cannot recover from: the person typing knows the
        // password is right, so the message points them at the one thing that is
        // not wrong. It cost an administrator a client demo — a temporary
        // password was issued to a dormant officer account from Staff & roles,
        // the sign-in was refused as a bad password, and the system looked like
        // it had failed to save the password it had in fact saved.
        //
        // Saying "deactivated" here reveals nothing to an attacker: you only
        // reach this line by already holding the account's password. Unknown
        // accounts, wrong passwords and soft-deleted accounts (hidden by the
        // model's global scope, so `$u` is null) all still get the one generic
        // message above.
        //
        // It is deliberately not counted as a failed attempt either. Nothing was
        // guessed, and locking the account for five minutes on top of refusing
        // it only buries the real reason further.
        if (! $u->is_active) {
            $this->error = 'This account is deactivated. An administrator must switch on "Allow this user to sign in" under Staff & roles.';

            Audit::record('Sign-in refused (deactivated)', null, [
                'subject' => $u,
                'subject_label' => $u->name,
                'context' => ['identifier' => Str::limit($v, 60)],
            ]);

            return null;
        }

        $throttle->clear();

        return $this->completeSignIn($u);
    }

    /** Grant the session. */
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

        session()->flash('toast', ['tone' => 'ok', 'title' => 'Signed in', 'msg' => 'Welcome back, '.$u->name]);

        return $this->redirect(route(Nav::firstScreen($u)), navigate: true);
    }
}; ?>

<div class="login-split">
    {{-- Marketing panel --}}
    <div class="login-hero">
        <div class="login-brand" style="display:flex;align-items:center;gap:14px">
            <img src="{{ asset('assets/bbt-logo-white.png') }}" alt="Big Binary Tech" style="height:52px;display:block" onerror="this.outerHTML='<span style=&quot;color:#fff;font-weight:800;font-size:var(--fs-xl)&quot;>Big Binary Tech</span>'">
        </div>
        <div style="position:relative;z-index:2;max-width:460px">
            <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:rgba(247,148,30,.16);border:1px solid rgba(247,148,30,.3);color:var(--orange2);font-size:var(--fs-xs);font-weight:600;letter-spacing:.02em;margin-bottom:22px">Institute Management System</div>
            <h1 style="font-size:var(--fs-4xl);line-height:1.15;font-weight:800;color:#fff;margin:0 0 16px;letter-spacing:-.02em">Registrations, fees &amp; access -<span style="color:var(--orange2)"> accountable by design.</span></h1>
            <p style="font-size:var(--fs-md);line-height:1.65;color:#b9bcdd;margin:0">Every action is scoped by permission and signed under the officer's name. Admins grant exactly what each role needs, nothing hardcoded, no loopholes.</p>
            <div style="display:flex;gap:26px;margin-top:34px">
                <div><div style="font-size:var(--fs-2xl);font-weight:800;color:#fff" class="tnum">2,000+</div><div style="font-size:var(--fs-xs);color:#9599c4;margin-top:2px">Managed users</div></div>
                <div style="width:1px;background:rgba(255,255,255,.12)"></div>
                <div><div style="font-size:var(--fs-2xl);font-weight:800;color:#fff" class="tnum">44</div><div style="font-size:var(--fs-xs);color:#9599c4;margin-top:2px">Active courses</div></div>
                <div style="width:1px;background:rgba(255,255,255,.12)"></div>
                <div><div style="font-size:var(--fs-2xl);font-weight:800;color:#fff">100%</div><div style="font-size:var(--fs-xs);color:#9599c4;margin-top:2px">Audited money</div></div>
            </div>
        </div>
        <div style="position:relative;z-index:2;font-size:var(--fs-xs);color:#7f83b0">© 2026 Big Binary Tech Institute · Secure staff portal</div>
    </div>

    {{-- Form panel --}}
    <div style="display:flex;align-items:center;justify-content:center;padding:40px;background:var(--bg)">
        <div style="width:100%;max-width:410px">
            <h2 style="font-size:var(--fs-2xl);font-weight:800;color:var(--ink);margin:0 0 6px;letter-spacing:-.01em">Sign in</h2>
            <p style="font-size:var(--fs-base);color:var(--muted);margin:0 0 24px">Enter your credentials to continue to the portal.</p>

            @if ($error)
                <div style="display:flex;align-items:center;gap:9px;padding:11px 13px;background:var(--over-bg);border:1px solid var(--over-br);border-radius:11px;margin-bottom:16px">
                    <x-icon name="alert-circle" :size="16" style="color:var(--over)" />
                    <span style="font-size:var(--fs-xs);color:var(--over);font-weight:600">{{ $error }}</span>
                </div>
            @endif

            <form wire:submit="login">
                <label class="label">Email or username</label>
                <input wire:model="user" type="text" placeholder="adminansar" class="input" style="margin-bottom:16px" autofocus>

                <label class="label">Password</label>
                {{-- `show` is scoped here, on the element that directly owns both
                     consumers below, and pinned with wire:key. Held on the outer
                     panel it was a sibling of the @error block, so a Livewire morph
                     that rewrote that block could replace the scope owner while the
                     input and button still evaluated `show`, throwing
                     "show is not defined" on every sign-in. Scope and consumers now
                     morph as one subtree. --}}
                <div style="position:relative;margin-bottom:16px" x-data="{ show: false }" wire:key="password-field">
                    <input wire:model="password" :type="show ? 'text' : 'password'" placeholder="Enter password" class="input" style="padding-right:74px">
                    <button @click="show = !show" type="button" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);height:32px;padding:0 11px;border:none;background:var(--surface3);color:var(--ink2);border-radius:8px;font-size:var(--fs-xs);font-weight:700;cursor:pointer" x-text="show ? 'Hide' : 'Show'"></button>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
                    <label style="display:flex;align-items:center;gap:8px;font-size:var(--fs-sm);color:var(--ink2);cursor:pointer"><input wire:model="remember" type="checkbox" style="width:16px;height:16px;accent-color:var(--iris)">Keep me signed in</label>
                    <a href="{{ route('password.request') }}" wire:navigate style="font-size:var(--fs-sm);font-weight:600">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;height:48px;font-size:var(--fs-md);margin-bottom:18px">
                    <x-icon name="signin" :size="18" /> Sign in
                </button>
            </form>

            <div style="display:flex;align-items:center;gap:8px;justify-content:center;font-size:var(--fs-xs);color:var(--faint)">
                <x-icon name="lock" :size="13" /> 256-bit TLS · role &amp; permission scoped
            </div>
        </div>
    </div>
</div>
