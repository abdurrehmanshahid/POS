<?php

use App\Services\Audit;
use App\Support\PasswordStrength;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Landing page for the emailed reset link (spec §5.2 rules, §17.2 delivery).
 *
 * Only reachable when INSTITUTE_SELF_SERVICE_RESET is on, and only with a valid
 * signed token issued by Laravel's password broker. The token is single-use,
 * expires in 60 minutes, and is verified against a hash held server-side, so
 * unlike the prototype's inline flow, possession of a username proves nothing.
 *
 * The strength rules are the spec's, unchanged: score < 3 is rejected, and the
 * meter shows Too weak / Weak / Fair / Good / Strong.
 */
new #[Layout('components.layouts.guest')] class extends Component {
    public string $token = '';

    public string $email = '';

    public string $pw1 = '';

    public string $pw2 = '';

    public string $error = '';

    public function mount(string $token): void
    {
        abort_unless(config('institute.self_service_reset'), 404);

        $this->token = $token;
        $this->email = (string) request()->string('email');
    }

    public function save()
    {
        $this->error = '';

        // Spec §5.2 strength floor, enforced server-side. The Alpine meter in
        // the markup is feedback only, it decides nothing.
        if (PasswordStrength::score($this->pw1) < 3) {
            $this->error = 'Password is too weak. Add length, a number and a symbol.';

            return null;
        }

        if ($this->pw1 !== $this->pw2) {
            $this->error = 'Passwords do not match.';

            return null;
        }

        $status = Password::reset(
            [
                'email' => $this->email,
                'password' => $this->pw1,
                'password_confirmation' => $this->pw2,
                'token' => $this->token,
            ],
            function ($user) {
                $user->forceFill([
                    'password' => $this->pw1,          // hashed by the model cast
                    'must_reset_password' => false,
                    'remember_token' => Str::random(60), // invalidate remember-me cookies
                ])->save();

                // Every other device holding a "keep me signed in" cookie for
                // this account is now logged out. If the reset was triggered
                // because the account was compromised, leaving those alive
                // would defeat the point of resetting at all.
                Audit::record('Password reset completed', null, [
                    'subject' => $user,
                    'subject_label' => $user->name,
                    'field' => 'password',
                    'old_value' => '(hidden)',
                    'new_value' => '(hidden)',
                ]);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Covers expired, already-used and forged tokens alike.
            $this->error = 'That reset link is invalid or has expired. Request a new one.';

            return null;
        }

        session()->flash('toast', ['tone' => 'ok', 'title' => 'Password updated', 'msg' => 'Sign in with your new password']);

        return $this->redirect(route('login'), navigate: true);
    }
}; ?>

<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px;background:var(--bg)">
    <div style="width:100%;max-width:420px" x-data="{ pw:'', score:0, calc(v){ this.pw=v; let s=0; if(v.length>=8)s++; if(/[a-z]/.test(v)&&/[A-Z]/.test(v))s++; if(/\d/.test(v))s++; if(/[^A-Za-z0-9]/.test(v))s++; this.score=s; }, colors:['var(--over)','var(--over)','var(--due)','var(--info)','var(--paid)'], labels:['Too weak','Weak','Fair','Good','Strong'], show:false }">
        <div class="card" style="padding:30px 28px">
            <h2 style="font-size:22px;font-weight:800;color:var(--ink);margin:0 0 6px;letter-spacing:-.01em">Choose a new password</h2>
            <p style="font-size:14px;color:var(--muted);margin:0 0 22px">Resetting the password for <strong style="color:var(--ink2)">{{ $email }}</strong>.</p>

            <form wire:submit="save">
                <label class="label">New password</label>
                <div style="position:relative;margin-bottom:10px">
                    <input wire:model="pw1" @input="calc($event.target.value)" :type="show ? 'text':'password'" placeholder="At least 8 characters" class="input" style="padding-right:74px" autofocus>
                    <button @click="show=!show" type="button" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);height:32px;padding:0 11px;border:none;background:var(--surface3);color:var(--ink2);border-radius:8px;font-size:12px;font-weight:700;cursor:pointer" x-text="show?'Hide':'Show'"></button>
                </div>
                <div style="display:flex;gap:5px;margin-bottom:6px">
                    <template x-for="i in 4" :key="i">
                        <div style="flex:1;height:5px;border-radius:3px" :style="{ background: i <= score ? colors[score] : 'var(--surface3)' }"></div>
                    </template>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:16px">
                    <span style="font-size:11.5px;color:var(--faint)">Strength: <span style="font-weight:700" :style="{ color: colors[score] }" x-text="labels[score]"></span></span>
                    <span style="font-size:11.5px;color:var(--faint)">Upper, lower, number &amp; symbol</span>
                </div>

                <label class="label">Confirm password</label>
                <input wire:model="pw2" :type="show ? 'text':'password'" placeholder="Re-enter password" class="input" style="margin-bottom:10px">

                @if ($error)
                    <div style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--over);margin-bottom:14px">
                        <x-icon name="alert-circle" :size="14" /> {{ $error }}
                    </div>
                @endif

                <button type="submit" class="btn btn-accent" style="width:100%;height:48px;font-size:15px;margin-top:6px">Update password</button>
            </form>
        </div>
    </div>
</div>
