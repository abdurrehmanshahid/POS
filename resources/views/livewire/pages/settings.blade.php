<?php

use App\Models\Setting;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $bank = '';
    public string $account = '';
    public string $iban = '';
    public bool $twofa = false;
    public int $nextSerial = 0;

    public function mount(): void
    {
        $s = Setting::current();
        $this->name = (string) $s->name;
        $this->bank = (string) $s->bank;
        $this->account = (string) $s->account;
        $this->iban = (string) $s->iban;
        $this->twofa = (bool) $s->twofa_required;
        $this->nextSerial = (int) $s->next_challan_serial;
    }

    public function save(): void
    {
        // Persist to the single settings row. audit_logs is challan-scoped, so
        // nothing is written there, the toast just reflects the policy copy.
        Setting::current()->update([
            'name' => $this->name,
            'bank' => $this->bank,
            'account' => $this->account,
            'iban' => $this->iban,
            'twofa_required' => $this->twofa,
        ]);

        $this->dispatch('bbt-toast', tone: 'ok', title: 'Settings saved', msg: 'Changes recorded in audit log');
    }
}; ?>

<div class="container-app anim-fade">
    <div style="margin-bottom:18px">
        <h2 style="font-size:21px;font-weight:800;color:var(--ink);letter-spacing:-.02em;margin:0">Settings</h2>
        <p style="font-size:13px;color:var(--muted);margin:5px 0 0">Institute identity, challan header and security</p>
    </div>

    {{-- Admin-only notice --}}
    <div style="display:flex;align-items:center;gap:10px;padding:11px 16px;background:var(--iris-bg);border:1px solid var(--border2);border-radius:12px;margin-bottom:22px">
        <x-icon name="lock" :size="17" style="color:var(--iris)" />
        <span style="font-size:13px;color:var(--iris);font-weight:600">Admin only. Changes to bank details and serials are recorded in the audit log.</span>
    </div>

    {{-- Institute & challan header --}}
    <div class="panel" style="margin-bottom:22px">
        <div class="panel-head"><x-icon name="settings" :size="18" style="color:var(--navy)" /><h3 class="panel-title">Institute &amp; challan header</h3></div>
        <div style="padding:22px">
            <div class="grid-2" style="margin-bottom:18px">
                <div>
                    <label class="label" for="s-name">Institute name</label>
                    <input id="s-name" class="input" type="text" wire:model="name" placeholder="Institute name">
                </div>
                <div>
                    <label class="label" for="s-bank">Bank name</label>
                    <input id="s-bank" class="input" type="text" wire:model="bank" placeholder="Bank name">
                </div>
                <div>
                    <label class="label" for="s-account">Account number</label>
                    <input id="s-account" class="input tnum" type="text" wire:model="account" placeholder="Account number">
                </div>
                <div>
                    <label class="label" for="s-iban">IBAN</label>
                    <input id="s-iban" class="input tnum" type="text" wire:model="iban" placeholder="PK00 XXXX 0000 0000 0000 0000">
                </div>
            </div>

            {{-- Next challan serial (read-only) --}}
            <div style="padding:14px 16px;background:var(--surface2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:space-between;gap:14px">
                <div>
                    <div class="label" style="margin-bottom:3px">Next challan serial</div>
                    <div style="font-size:12px;color:var(--muted)">System-generated &amp; atomic · cannot be reset by hand.</div>
                </div>
                <span class="tnum" style="font-size:15px;font-weight:800;color:var(--ink);font-family:ui-monospace,'SF Mono',Menlo,monospace">{{ \App\Services\Sequences::challanNo($nextSerial) }}</span>
            </div>
        </div>
    </div>

    {{-- Security --}}
    <div class="panel" style="margin-bottom:22px">
        <div class="panel-head"><x-icon name="lock" :size="18" style="color:var(--iris)" /><h3 class="panel-title">Security</h3></div>
        <div style="padding:20px 22px">
            <div style="display:flex;align-items:center;gap:14px" x-data="{ on: @entangle('twofa') }">
                <button type="button" role="switch" :aria-checked="on" @click="on = !on"
                        style="position:relative;width:46px;height:27px;border-radius:999px;border:none;cursor:pointer;flex:0 0 auto;transition:background .15s"
                        :style="on ? 'background:var(--paid)' : 'background:var(--border2)'">
                    <span style="position:absolute;top:3px;left:3px;width:21px;height:21px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s"
                          :style="on ? 'transform:translateX(19px)' : ''"></span>
                </button>
                <div style="font-size:13.5px;font-weight:600;color:var(--ink2);cursor:pointer" @click="on = !on">Require two-factor for admins on new devices</div>
            </div>
        </div>
    </div>

    {{-- Save --}}
    <div style="display:flex;justify-content:flex-end">
        <button class="btn btn-primary" wire:click="save">
            <x-icon name="check" :size="17" /> Save changes
        </button>
    </div>
</div>
