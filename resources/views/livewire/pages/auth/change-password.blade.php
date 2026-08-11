<?php

use App\Services\Audit;
use App\Support\PasswordStrength;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;

/**
 * Change your own password, while signed in.
 *
 * The gap this fills: every route to a new password went through somebody else.
 * A superadmin could issue a temporary one, and the forgot-password link could
 * email one — so a member of staff who merely suspected their password was
 * known had to either ask the owner or lock themselves out to get a link. The
 * ordinary, quiet act of rotating your own credential had no door at all.
 *
 * Three rules, and each is here because its absence is a real attack:
 *
 *   The CURRENT password is required. Without it an unlocked laptop is a
 *   permanent account takeover — the attacker sets a password the owner does
 *   not know and keeps the session. Requiring it makes the attacker prove they
 *   are the person, not merely that they are at the desk.
 *
 *   Other sessions are logged out. A password change that leaves the thief's
 *   session alive has changed nothing about the situation it was performed to
 *   fix. `logoutOtherDevices` re-hashes the remember token, so the stolen
 *   cookie dies too.
 *
 *   Attempts are rate limited. The current-password field is an oracle: given
 *   an unattended session, an attacker can guess at the real password
 *   offline-fast. Five tries a minute makes that worthless without making the
 *   honest typo painful.
 */
new class extends Component {
    public string $current = '';
    public string $pw1 = '';
    public string $pw2 = '';
    public string $error = '';

    /**
     * Never reachable while impersonating.
     *
     * The owner can sign in as any member of staff. Letting that session reach
     * this screen would let them set someone else's password — a credential
     * they could then use directly, with the audit trail showing only that the
     * staff member changed their own. Hiding the menu item is not enough; the
     * URL is one word long.
     */
    public function mount(): void
    {
        abort_if(session()->has('impersonator_id'), 403,
            'Stop viewing as this user before changing a password.');
    }

    public function save(): void
    {
        $this->error = '';
        abort_if(session()->has('impersonator_id'), 403);

        $user = auth()->user();

        // Keyed by the account, not the IP: the threat here is somebody at this
        // person's desk, and they share the office IP with everyone else.
        $key = 'change-password:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->error = 'Too many attempts. Try again in '
                .ceil(RateLimiter::availableIn($key) / 60).' minute(s).';

            return;
        }

        if (! Hash::check($this->current, $user->password)) {
            RateLimiter::hit($key, 300);
            $this->error = 'That is not your current password.';
            $this->reset('current');

            return;
        }

        if (PasswordStrength::score($this->pw1) < 3) {
            $this->error = 'New password is too weak. Add length, a number and a symbol.';

            return;
        }

        if ($this->pw1 !== $this->pw2) {
            $this->error = 'The new passwords do not match.';

            return;
        }

        // Refusing a no-op rather than accepting it silently: somebody who
        // re-enters what they already had has not done the thing they came here
        // to do, and telling them so is the difference between a rotated
        // credential and the belief that one was rotated.
        if (Hash::check($this->pw1, $user->password)) {
            $this->error = 'That is already your password. Choose a different one.';

            return;
        }

        $user->forceFill([
            'password' => Hash::make($this->pw1),
            // Cleared, because they have just chosen their own: leaving it set
            // would send them straight back to the forced-reset screen on the
            // next request, in a loop.
            'must_reset_password' => false,
        ])->save();

        RateLimiter::clear($key);

        // Everywhere but here. The whole point is to end any session the person
        // did not open, and this one they did.
        Auth::logoutOtherDevices($this->pw1);

        // The value is never recorded, only the fact. An audit row that could
        // reconstruct a credential is a worse liability than no row at all.
        Audit::record('Password changed', $user, [
            'subject' => $user,
            'subject_label' => $user->name,
            'field' => 'password',
            'old_value' => 'set by the account holder',
            'new_value' => 'changed; other sessions signed out',
        ]);

        $this->reset('current', 'pw1', 'pw2');

        $this->dispatch('bbt-toast', tone: 'ok', title: 'Password changed',
            msg: 'Any other device signed in as you has been signed out.');
    }
}; ?>

<div class="container-app anim-fade" style="max-width:520px">
    <div style="margin-bottom:18px">
        <h1 style="font-size:var(--fs-lg);font-weight:800;color:var(--ink);margin:0">Change your password</h1>
        <p style="font-size:var(--fs-sm);color:var(--muted);margin:4px 0 0">
            You will stay signed in here. Every other device signed in as you will be signed out.
        </p>
    </div>

    <div class="card" style="padding:26px 24px"
         x-data="{ pw:'', score:0, calc(v){ let s=0; if(v.length>=8)s++; if(/[a-z]/.test(v)&&/[A-Z]/.test(v))s++; if(/\d/.test(v))s++; if(/[^A-Za-z0-9]/.test(v))s++; this.score=s; }, colors:['var(--over)','var(--over)','var(--due)','var(--info)','var(--paid)'], labels:['Too weak','Weak','Fair','Good','Strong'], show:false }">
        <form wire:submit="save">
            <label class="label">Current password</label>
            <input wire:model="current" type="password" placeholder="The password you use now"
                   class="input" style="margin-bottom:18px" autocomplete="current-password" autofocus>

            <label class="label">New password</label>
            <div style="position:relative;margin-bottom:10px">
                <input wire:model="pw1" @input="calc($event.target.value)" :type="show ? 'text':'password'"
                       placeholder="At least 8 characters" class="input" style="padding-right:74px"
                       autocomplete="new-password">
                <button @click="show=!show" type="button" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);height:32px;padding:0 11px;border:none;background:var(--surface3);color:var(--ink2);border-radius:8px;font-size:var(--fs-xs);font-weight:700;cursor:pointer" x-text="show?'Hide':'Show'"></button>
            </div>
            <div style="display:flex;gap:5px;margin-bottom:6px">
                <template x-for="i in 4" :key="i">
                    <div style="flex:1;height:5px;border-radius:3px" :style="{ background: i <= score ? colors[score] : 'var(--surface3)' }"></div>
                </template>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:16px">
                <span style="font-size:var(--fs-2xs);color:var(--faint)">Strength: <span style="font-weight:700" :style="{ color: colors[score] }" x-text="labels[score]"></span></span>
                <span style="font-size:var(--fs-2xs);color:var(--faint)">Upper, lower, number &amp; symbol</span>
            </div>

            <label class="label">Confirm new password</label>
            <input wire:model="pw2" :type="show ? 'text':'password'" placeholder="Re-enter the new password"
                   class="input" style="margin-bottom:10px" autocomplete="new-password">

            @if ($error)
                <div style="display:flex;align-items:center;gap:7px;font-size:var(--fs-xs);color:var(--over);margin-bottom:14px">
                    <x-icon name="alert-circle" :size="14" /> {{ $error }}
                </div>
            @endif

            <button type="submit" class="btn btn-accent" style="width:100%;height:46px;font-size:var(--fs-base);margin-top:6px">
                <x-icon name="key" :size="16" /> Change password
            </button>
        </form>
    </div>
</div>
