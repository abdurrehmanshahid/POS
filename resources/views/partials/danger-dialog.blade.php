{{-- Shared destructive-action dialog. Driven by App\Support\Concerns\ConfirmsDangerously.
     The host component supplies dangerKind/Title/Body/Phrase and implements
     confirmDanger(). Both gates, type-to-confirm and step-up, are enforced
     server-side in dangerCleared(); this markup only collects them.

     Rendered with a plain Blade @if rather than Alpine's <template x-if>, and
     that is deliberate. With x-if, Alpine clones the template's contents the
     instant the entangled flag flips, which can race Livewire's DOM morph, the
     dialog then appears carrying the property values from the PREVIOUS render,
     i.e. the empty defaults. On a confirmation dialog that failure is dangerous
     rather than merely ugly: you would be shown a blank title and no
     type-to-confirm field while the server still holds a live purge target.
     Server-rendering it means the markup and the state can never disagree. --}}
@if ($dangerOpen)
    <div class="drawer-backdrop" style="z-index:90" wire:click="closeDanger"></div>

    <div style="position:fixed;z-index:91;inset:0;display:flex;align-items:center;justify-content:center;padding:24px;pointer-events:none">
        <div class="card" style="width:100%;max-width:470px;pointer-events:auto;padding:0;overflow:hidden">

            {{-- Header --}}
            <div style="display:flex;gap:13px;padding:20px 22px;border-bottom:1px solid var(--border);background:{{ $dangerIrreversible ? 'var(--over-bg)' : 'var(--surface2)' }}">
                <div style="display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:11px;flex:none;background:{{ $dangerIrreversible ? 'var(--over)' : 'var(--due)' }}">
                    <x-icon name="{{ $dangerIrreversible ? 'trash' : 'alert' }}" :size="19" style="color:#fff" />
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:var(--fs-md);font-weight:800;color:var(--ink);letter-spacing:-.01em">{{ $dangerTitle }}</div>
                    @if ($dangerIrreversible)
                        <div style="font-size:var(--fs-2xs);font-weight:700;color:var(--over);letter-spacing:.05em;text-transform:uppercase;margin-top:3px">Cannot be undone</div>
                    @endif
                </div>
            </div>

            <div style="padding:20px 22px">
                <p style="font-size:var(--fs-sm);color:var(--ink2);line-height:1.6;margin:0 0 18px">{{ $dangerBody }}</p>

                @if ($dangerNeedsReason)
                    <label class="label">Reason</label>
                    <textarea wire:model="dangerReason" class="input" rows="2"
                              placeholder="Recorded in the audit trail"
                              style="margin-bottom:16px;resize:vertical;min-height:62px"></textarea>
                @endif

                @if ($dangerPhrase !== '')
                    <label class="label">
                        Type <span class="tnum" style="color:var(--over);font-weight:800">{{ $dangerPhrase }}</span> to confirm
                    </label>
                    <input wire:model="dangerTyped" type="text" class="input tnum"
                           placeholder="{{ $dangerPhrase }}" style="margin-bottom:16px" autocomplete="off">
                @endif

                <label class="label">Your password</label>
                <input wire:model="dangerSecret" type="password" class="input"
                       placeholder="Re-enter your password" autocomplete="current-password">

                @if ($dangerError)
                    <div style="display:flex;align-items:flex-start;gap:7px;font-size:var(--fs-xs);color:var(--over);margin-top:12px;line-height:1.5">
                        <x-icon name="alert-circle" :size="14" style="flex:none;margin-top:1px" /> {{ $dangerError }}
                    </div>
                @endif
            </div>

            <div style="display:flex;gap:10px;padding:16px 22px;border-top:1px solid var(--border);background:var(--surface2)">
                <button class="btn btn-ghost" style="flex:1" wire:click="closeDanger">Cancel</button>
                <button class="btn" style="flex:1;background:{{ $dangerIrreversible ? 'var(--over)' : 'var(--due)' }};color:#fff;border:none"
                        wire:click="confirmDanger" wire:loading.attr="disabled">
                    {{ $dangerConfirmLabel }}
                </button>
            </div>
        </div>
    </div>
@endif
