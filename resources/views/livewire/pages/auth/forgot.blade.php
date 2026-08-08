<?php

use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Forgot password (spec §5.2, rebuilt, see spec §17.2).
 *
 * ---------------------------------------------------------------------------
 * WHAT WAS HERE BEFORE, AND WHY IT HAD TO GO
 * ---------------------------------------------------------------------------
 * The prototype's flow was: type a username → the screen confirms that account
 * exists → set a new password inline. Ported faithfully, that is a complete
 * authentication bypass. Anyone who could reach the login page and guess
 * "adminansar", a username printed on the login screen itself, owned the
 * administrator account in about ten seconds, with no email, no token and no
 * proof of identity. On the way past it also confirmed which usernames were
 * real, handing over a free account-enumeration oracle.
 *
 * ---------------------------------------------------------------------------
 * WHAT REPLACES IT
 * ---------------------------------------------------------------------------
 * Nothing on this screen can change a password any more. Two safe paths exist:
 *
 *  1. **Administrator-issued reset (default, needs no mail server).** A super
 *     admin, or an admin holding `staff.manage`, issues a one-time temporary
 *     password from the staff screen. It is shown once, forces a change at next
 *     sign-in, and writes an audit row naming who issued it.
 *
 *  2. **Tokenised email link (opt-in).** Set INSTITUTE_SELF_SERVICE_RESET=true
 *     once SMTP is configured, and this screen sends a signed, expiring link
 *     through Laravel's password broker. Turning it on is a config change, not
 *     a code change.
 *
 * In BOTH modes the reply to a submitted address is identical whether or not an
 * account matched. Never tell an unauthenticated stranger who banks here.
 */
new #[Layout('components.layouts.guest')] class extends Component {
    public string $user = '';

    public string $error = '';

    public string $sent = '';

    public function enabled(): bool
    {
        return (bool) config('institute.self_service_reset');
    }

    public function submit(): void
    {
        $this->error = '';

        // Server-side gate, not just a hidden form: with the flag off this
        // action does not exist at all.
        abort_unless($this->enabled(), 404);

        $v = Str::lower(trim($this->user));

        if ($v === '') {
            $this->error = 'Enter your email or username.';

            return;
        }

        // Throttled independently of sign-in. Without this the endpoint is a
        // free mail cannon pointed at whatever address an attacker names.
        $key = 'pwreset:'.sha1($v.'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->error = 'Too many reset requests. Try again later.';

            return;
        }
        RateLimiter::hit($key, 900);

        $u = User::query()
            ->where(fn ($q) => $q->whereRaw('LOWER(username) = ?', [$v])->orWhereRaw('LOWER(email) = ?', [$v]))
            ->first();

        // Send only for a real, usable account, but say the same thing either
        // way, so the branch is invisible from outside.
        if ($u && $u->is_active) {
            Password::sendResetLink(['email' => $u->email]);
            Audit::record('Password reset requested', null, [
                'subject' => $u,
                'subject_label' => $u->name,
            ]);
        }

        $this->sent = 'If an account matches that email or username, a reset link is on its way. The link expires in 60 minutes.';
        $this->user = '';
    }
}; ?>

<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px;background:var(--bg)">
    <div style="width:100%;max-width:430px">
        <div class="card" style="padding:30px 28px">

            @if ($this->enabled())
                <h2 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);margin:0 0 6px;letter-spacing:-.01em">Reset your password</h2>
                <p style="font-size:var(--fs-base);color:var(--muted);margin:0 0 22px">We'll email you a secure link to choose a new password.</p>

                @if ($sent)
                    <div style="display:flex;gap:9px;padding:12px 13px;background:var(--paid-bg);border:1px solid var(--paid-br);border-radius:11px;margin-bottom:18px">
                        <x-icon name="check" :size="16" style="color:var(--paid);flex:none;margin-top:1px" />
                        <span style="font-size:var(--fs-xs);color:var(--paid);font-weight:600;line-height:1.55">{{ $sent }}</span>
                    </div>
                @else
                    <form wire:submit="submit">
                        <label class="label">Email or username</label>
                        <input wire:model="user" type="text" placeholder="you@bbt.edu.pk" class="input" style="margin-bottom:14px" autofocus>

                        @if ($error)
                            <div style="display:flex;align-items:center;gap:7px;font-size:var(--fs-xs);color:var(--over);margin-bottom:14px">
                                <x-icon name="alert-circle" :size="14" /> {{ $error }}
                            </div>
                        @endif

                        <button type="submit" class="btn btn-accent" style="width:100%;height:48px;font-size:var(--fs-md)">Send reset link</button>
                    </form>
                @endif
            @else
                {{-- Default posture: no self-service reset exists at all. --}}
                <div style="display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:var(--iris-bg);margin-bottom:16px">
                    <x-icon name="lock" :size="20" style="color:var(--iris)" />
                </div>
                <h2 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);margin:0 0 6px;letter-spacing:-.01em">Password help</h2>
                <p style="font-size:var(--fs-base);color:var(--muted);margin:0 0 20px;line-height:1.65">
                    Staff passwords are reset by an administrator, never from this page. Ask your administrator to issue a temporary password, you'll be asked to choose a new one the moment you sign in.
                </p>
                <div style="padding:13px 15px;border:1px dashed var(--border2);border-radius:12px;background:var(--surface2);margin-bottom:18px">
                    <div style="font-size:var(--fs-2xs);font-weight:700;color:var(--faint);letter-spacing:.06em;text-transform:uppercase;margin-bottom:7px">Accounts office</div>
                    <div class="tnum" style="font-size:var(--fs-sm);color:var(--ink2);line-height:1.7">
                        accounts@bbt.edu.pk<br>+92 42 000 0000
                    </div>
                </div>
            @endif

            <a href="{{ route('login') }}" wire:navigate style="display:flex;align-items:center;justify-content:center;gap:7px;font-size:var(--fs-sm);font-weight:600;margin-top:6px">
                <x-icon name="arrow-left" :size="14" /> Back to sign in
            </a>
        </div>
    </div>
</div>
