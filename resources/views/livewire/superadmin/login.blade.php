<?php

use App\Models\SuperAdmin;
use App\Services\Audit;
use App\Services\TwoFactorChallenge;
use App\Support\LoginThrottle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Super admin sign-in at /superadmin.
 *
 * Same hardening as the staff login, dual-key throttle, constant-time failure,
 * identical message whether or not the account exists, with one difference:
 * there is no "forgot password" and no demo helper of any kind. If this account
 * is lost, recovery is a deliberate act at the database, which is the correct
 * cost for the identity that can delete everything.
 *
 * The page also deliberately gives away nothing. An unauthenticated visitor
 * cannot tell from this screen whether /superadmin is even a real endpoint for
 * this institute, because a wrong username and a wrong password fail alike.
 */
new #[Layout('components.layouts.guest')] class extends Component {
    private const DUMMY_HASH = '$2y$12$Usi/Ae/dK.T3FMwZZ.mchuGkkmHk7NxUas/YD6acHvUQJ.5Nax7ae';

    public string $user = '';

    public string $password = '';

    public string $error = '';

    public function login()
    {
        $this->error = '';
        $throttle = LoginThrottle::for('su:'.$this->user, request()->ip());

        if ($throttle->isLocked()) {
            $this->error = $throttle->lockedMessage();

            return null;
        }

        $v = Str::lower(trim($this->user));
        $su = SuperAdmin::query()
            ->where(fn ($q) => $q->whereRaw('LOWER(username) = ?', [$v])->orWhereRaw('LOWER(email) = ?', [$v]))
            ->first();

        $passwordOk = $su
            ? Hash::check($this->password, $su->password)
            : Hash::check($this->password, self::DUMMY_HASH);

        if (! $su || ! $su->is_active || ! $passwordOk) {
            $attempts = $throttle->recordFailure();
            $this->error = $throttle->failureMessage($attempts);

            // Failed attempts against the owner account are worth shouting
            // about, this is the single most valuable credential in the system.
            Audit::record('Super admin sign-in failed', null, [
                'subject_label' => Str::limit($v, 60),
                'context' => ['identifier' => Str::limit($v, 60), 'attempt' => $attempts],
            ]);

            return null;
        }

        $throttle->clear();

        // Enrolled → withhold the session until a code is supplied.
        if ($su->hasTwoFactorEnabled()) {
            app(TwoFactorChallenge::class)->start($su, 'superadmin', false);

            return $this->redirect(route('two-factor.challenge'), navigate: false);
        }

        // Not yet enrolled → a session that can reach the setup screen and
        // nothing else (EnsureTwoFactorEnrolled pins it there).
        Auth::guard('superadmin')->login($su);
        session()->regenerate();

        $su->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->save();
        Audit::record('Super admin signed in', $su, ['subject' => $su, 'subject_label' => $su->name]);

        return $this->redirect(route('superadmin.two-factor.setup'), navigate: true);
    }
}; ?>

<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px;background:radial-gradient(900px 500px at 50% -10%,#2a2660 0%,transparent 60%),#141230">
    <div style="width:100%;max-width:400px" x-data="{ show: false }">

        <div style="text-align:center;margin-bottom:26px">
            <div style="display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:15px;background:rgba(142,136,230,.16);border:1px solid rgba(142,136,230,.3);margin-bottom:14px">
                <x-icon name="shield" :size="24" style="color:#a9a3ee" />
            </div>
            <h2 style="font-size:var(--fs-2xl);font-weight:800;color:#fff;margin:0 0 5px;letter-spacing:-.02em">Super Admin</h2>
            <p style="font-size:var(--fs-sm);color:#9599c4;margin:0">Platform administration · restricted</p>
        </div>

        <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:26px 24px;box-shadow:0 18px 50px rgba(0,0,0,.35)">
            @if ($error)
                <div style="display:flex;align-items:center;gap:9px;padding:11px 13px;background:var(--over-bg);border:1px solid var(--over-br);border-radius:11px;margin-bottom:16px">
                    <x-icon name="alert-circle" :size="16" style="color:var(--over);flex:none" />
                    <span style="font-size:var(--fs-xs);color:var(--over);font-weight:600">{{ $error }}</span>
                </div>
            @endif

            <form wire:submit="login">
                <label class="label">Username or email</label>
                <input wire:model="user" type="text" class="input" style="margin-bottom:16px" autofocus autocomplete="username">

                <label class="label">Password</label>
                <div style="position:relative;margin-bottom:20px">
                    <input wire:model="password" :type="show ? 'text' : 'password'" class="input" style="padding-right:74px" autocomplete="current-password">
                    <button @click="show = !show" type="button" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);height:32px;padding:0 11px;border:none;background:var(--surface3);color:var(--ink2);border-radius:8px;font-size:var(--fs-xs);font-weight:700;cursor:pointer" x-text="show ? 'Hide' : 'Show'"></button>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;height:48px;font-size:var(--fs-md)">
                    <x-icon name="signin" :size="18" /> Continue
                </button>
            </form>

            <div style="display:flex;align-items:center;gap:8px;justify-content:center;font-size:var(--fs-2xs);color:var(--faint);margin-top:18px;line-height:1.5;text-align:center">
                <x-icon name="lock" :size="13" style="flex:none" />
                Two-factor is mandatory. Password resets are not available here.
            </div>
        </div>

        <div style="text-align:center;margin-top:20px">
            <a href="{{ route('login') }}" style="font-size:var(--fs-xs);color:#9599c4;font-weight:600">Staff portal instead</a>
        </div>
    </div>
</div>
