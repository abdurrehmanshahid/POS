<?php

use App\Support\Nav;
use App\Support\PasswordStrength;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/** First-login password reset (spec §2.1 / §9.7). */
new #[Layout('components.layouts.guest')] class extends Component {
    public string $pw1 = '';
    public string $pw2 = '';
    public string $error = '';

    public function save()
    {
        $this->error = '';
        if (PasswordStrength::score($this->pw1) < 3) {
            $this->error = 'Password is too weak. Add length, a number and a symbol.';

            return null;
        }
        if ($this->pw1 !== $this->pw2) {
            $this->error = 'Passwords do not match.';

            return null;
        }

        $user = auth()->user();
        $user->update(['password' => Hash::make($this->pw1), 'must_reset_password' => false]);

        session()->flash('toast', ['tone' => 'ok', 'title' => 'Password set', 'msg' => 'Welcome to the portal']);

        return $this->redirect(route(Nav::firstScreen($user)), navigate: true);
    }
}; ?>

<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px;background:var(--bg)">
    <div style="width:100%;max-width:420px" x-data="{ pw:'', score:0, calc(v){ this.pw=v; let s=0; if(v.length>=8)s++; if(/[a-z]/.test(v)&&/[A-Z]/.test(v))s++; if(/\d/.test(v))s++; if(/[^A-Za-z0-9]/.test(v))s++; this.score=s; }, colors:['var(--over)','var(--over)','var(--due)','var(--info)','var(--paid)'], labels:['Too weak','Weak','Fair','Good','Strong'], show:false }">
        <div class="card" style="padding:30px 28px">
            <h2 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);margin:0 0 6px;letter-spacing:-.01em">Set your password</h2>
            <p style="font-size:var(--fs-base);color:var(--muted);margin:0 0 22px">Choose a strong password to finish setting up your account.</p>

            <form wire:submit="save">
                <label class="label">New password</label>
                <div style="position:relative;margin-bottom:10px">
                    <input wire:model="pw1" @input="calc($event.target.value)" :type="show ? 'text':'password'" placeholder="At least 8 characters" class="input" style="padding-right:74px" autofocus>
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

                <label class="label">Confirm password</label>
                <input wire:model="pw2" :type="show ? 'text':'password'" placeholder="Re-enter password" class="input" style="margin-bottom:10px">

                @if ($error)
                    <div style="display:flex;align-items:center;gap:7px;font-size:var(--fs-xs);color:var(--over);margin-bottom:14px">
                        <x-icon name="alert-circle" :size="14" /> {{ $error }}
                    </div>
                @endif

                <button type="submit" class="btn btn-accent" style="width:100%;height:48px;font-size:var(--fs-md);margin-top:6px">Set password &amp; continue</button>
            </form>
        </div>
    </div>
</div>
