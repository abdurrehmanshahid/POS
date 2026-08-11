<?php

use App\Services\Audit;
use App\Support\PasswordStrength;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The owner rotating their own password.
 *
 * The staff twin of this screen fills a convenience gap. This one fills a
 * security hole, and it is worth naming the difference: the superadmin can read
 * every record, download a full SQL backup, purge a student permanently and
 * sign in as any member of staff. It was the single most privileged credential
 * in the institute and the ONLY one with no way to change it — not by
 * self-service, not by an administrator, not by an emailed link. A superadmin
 * who believed their password was compromised had no move except to ask a
 * developer to run a database command.
 *
 * The rules are the staff screen's, for the same reasons, and they matter more
 * here in proportion to what the account can do:
 *
 *   The current password is required, so an unlocked console is not a permanent
 *   takeover of the whole institute.
 *   Other sessions are ended, including any live impersonation — leaving a
 *   thief's session alive would defeat the act entirely.
 *   Attempts are rate limited, because the current-password field is an oracle.
 */
new #[Layout('components.layouts.superadmin')] class extends Component {
    public string $current = '';
    public string $pw1 = '';
    public string $pw2 = '';
    public string $error = '';

    public function save(): void
    {
        $this->error = '';
        $owner = Auth::guard('superadmin')->user();

        $key = 'sa-change-password:'.$owner->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->error = 'Too many attempts. Try again in '
                .ceil(RateLimiter::availableIn($key) / 60).' minute(s).';

            return;
        }

        if (! Hash::check($this->current, $owner->password)) {
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

        if (Hash::check($this->pw1, $owner->password)) {
            $this->error = 'That is already your password. Choose a different one.';

            return;
        }

        $owner->forceFill(['password' => Hash::make($this->pw1)])->save();

        RateLimiter::clear($key);

        // Guard-scoped: this ends other `superadmin` sessions and leaves this
        // one alive. It also re-hashes the remember token, so a stolen cookie
        // stops working.
        Auth::guard('superadmin')->logoutOtherDevices($this->pw1);

        // Never the value, only the fact. On the owner's account this row is
        // also the thing a later investigation reads to establish WHEN control
        // of the institute last changed hands.
        Audit::record('Super admin password changed', $owner, [
            'subject' => $owner,
            'subject_label' => $owner->name,
            'field' => 'password',
            'old_value' => 'set by the owner',
            'new_value' => 'changed; other sessions signed out',
        ]);

        $this->reset('current', 'pw1', 'pw2');

        $this->dispatch('bbt-toast', tone: 'ok', title: 'Password changed',
            msg: 'Any other device signed in as the owner has been signed out.');
    }
}; ?>

<div class="container-app anim-fade" style="max-width:520px">
    <div style="margin-bottom:18px">
        <h1 style="font-size:var(--fs-lg);font-weight:800;color:var(--ink);margin:0">Change owner password</h1>
        <p style="font-size:var(--fs-sm);color:var(--muted);margin:4px 0 0">
            This account can read, export and destroy every record. You will stay signed in here;
            every other device signed in as the owner will be signed out.
        </p>
    </div>

    <div class="card" style="padding:26px 24px"
         x-data="{ score:0, calc(v){ let s=0; if(v.length>=8)s++; if(/[a-z]/.test(v)&&/[A-Z]/.test(v))s++; if(/\d/.test(v))s++; if(/[^A-Za-z0-9]/.test(v))s++; this.score=s; }, colors:['var(--over)','var(--over)','var(--due)','var(--info)','var(--paid)'], labels:['Too weak','Weak','Fair','Good','Strong'], show:false }">
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
