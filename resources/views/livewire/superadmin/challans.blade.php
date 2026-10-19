<?php

use App\Models\Challan;
use App\Services\ChallanEdits;
use App\Services\StepUp;
use App\Support\Format;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Every fee challan, and the one place its amounts can be corrected.
 *
 * Staff never edit an issued challan. The super admin can, behind a reason and
 * their password again; the rules live in ChallanEdits.
 */
new #[Layout('components.layouts.super')] class extends Component {
    use WithPagination;

    public string $q = '';

    public ?int $editId = null;

    // Strings because they are bound to number boxes, and a cleared box
    // arrives as ''.
    public string $editBase = '';
    public string $editDiscount = '';
    public string $editCertificate = '';
    /** Money collected so far, for correcting a payment typed wrong. */
    public string $editCollected = '';
    public string $editReason = '';
    public string $editSecret = '';
    public string $editError = '';

    private function actor()
    {
        return auth()->guard('superadmin')->user();
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function askEdit(int $id): void
    {
        $c = Challan::findOrFail($id);

        $this->editId = $c->id;
        $this->editBase = (string) $c->base_amount;
        $this->editDiscount = (string) $c->discount_amount;
        $this->editCertificate = (string) $c->certificate_amount;
        $this->editCollected = (string) $c->paidAmount();
        $this->editReason = '';
        $this->editSecret = '';
        $this->editError = '';
    }

    public function closeEdit(): void
    {
        $this->reset('editId', 'editBase', 'editDiscount', 'editCertificate', 'editCollected', 'editReason', 'editSecret', 'editError');
    }

    /** Whole rupees, or null for anything else (blank, decimals, minus). */
    private function rupees(string $value): ?int
    {
        $value = trim($value);

        return ctype_digit($value) ? (int) $value : null;
    }

    public function saveEdit(): void
    {
        $this->editError = '';
        $challan = $this->editId ? Challan::find($this->editId) : null;

        if (! $challan) {
            $this->editError = 'That challan no longer exists.';

            return;
        }

        $base = $this->rupees($this->editBase);
        $discount = $this->rupees($this->editDiscount);
        $certificate = $this->rupees($this->editCertificate);
        $collected = $this->rupees($this->editCollected);

        if ($base === null || $discount === null || $certificate === null || $collected === null) {
            $this->editError = 'Enter every amount in whole rupees (0 for none).';

            return;
        }

        try {
            app(StepUp::class)->confirm($this->actor(), $this->editSecret);
            $edited = app(ChallanEdits::class)->edit($challan, $this->actor(), [
                'base_amount' => $base,
                'discount_amount' => $discount,
                'certificate_amount' => $certificate,
                'collected' => $collected,
            ], $this->editReason);
        } catch (\RuntimeException $e) {
            $this->editError = $e->getMessage();

            return;
        }

        $this->closeEdit();
        $this->dispatch('bbt-toast', tone: 'ok', title: 'Challan updated',
            msg: $edited->challan_no.' · net payable '.Format::money($edited->net_amount));
    }

    public function with(): array
    {
        $rows = Challan::query()
            ->with(['student' => fn ($q) => $q->withTrashed(), 'admissions.course', 'payments.reversals', 'installments'])
            ->when($this->q !== '', function ($q) {
                $term = '%'.Str::lower(trim($this->q)).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(challan_no) LIKE ?', [$term])
                    ->orWhereHas('student', fn ($s) => $s->withTrashed()->where(fn ($s) => $s
                        ->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(student_code) LIKE ?', [$term]))));
            })
            ->latest('id')
            ->paginate(30);

        $editing = $this->editId ? $rows->firstWhere('id', $this->editId) ?? Challan::with('payments.reversals')->find($this->editId) : null;
        $base = $this->rupees($this->editBase) ?? 0;
        $discount = $this->rupees($this->editDiscount) ?? 0;
        $certificate = $this->rupees($this->editCertificate) ?? 0;

        return [
            'rows' => $rows,
            'editing' => $editing,
            'newNet' => $base - $discount + $certificate,
            'newCollected' => $this->rupees($this->editCollected) ?? 0,
        ];
    }
}; ?>

@php
    $pill = fn ($s) => match ($s) {
        'paid' => ['paid', 'Paid'],
        'overdue' => ['overdue', 'Overdue'],
        'installment' => ['installment', 'Installment'],
        default => ['unpaid', 'Unpaid'],
    };
@endphp

<div class="container-app anim-fade">

    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px">
        <div class="search" style="width:340px;max-width:100%">
            <x-icon name="search" :size="15" />
            <input wire:model.live.debounce.250ms="q" placeholder="Search challan no, student name or ID…" class="input">
        </div>
    </div>

    <div class="panel">
        <div class="scroll-x">
            <table class="table">
                <thead>
                    <tr><th>Challan</th><th>Student</th><th>For</th><th class="right">Net payable</th><th class="right">Collected</th><th>Status</th><th class="right actions-col">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $c)
                        @php
                            $cancelled = ! $c->isCharge() && $c->admissions->where('status', '!=', 'cancelled')->isEmpty();
                            [$tone, $label] = $cancelled ? ['cancelled', 'Cancelled'] : $pill($c->paymentState());
                        @endphp
                        <tr @style(['opacity:.55' => $cancelled])>
                            <td class="tnum" style="color:var(--iris);font-weight:700;white-space:nowrap">{{ $c->challan_no }}</td>
                            <td>
                                <div style="font-weight:600;color:var(--ink)">{{ $c->student?->name ?? '—' }}</div>
                                <div class="tnum" style="font-size:var(--fs-xs);color:var(--muted)">{{ $c->student?->student_code }}</div>
                            </td>
                            <td style="color:var(--ink2)">{{ $c->subject() }}</td>
                            <td class="right tnum" style="font-weight:700">{{ Format::money($c->net_amount) }}</td>
                            <td class="right tnum" style="color:var(--muted)">{{ Format::money($c->paidAmount()) }}</td>
                            <td><x-ui.pill :tone="$tone">{{ $label }}</x-ui.pill></td>
                            <td class="right actions-col">
                                <button class="btn btn-ghost btn-sm" wire:click="askEdit({{ $c->id }})" @disabled($cancelled)
                                        title="{{ $cancelled ? 'The registration is cancelled' : 'Edit the amounts on this challan' }}">
                                    <x-icon name="edit" :size="14" /> Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <x-ui.table-empty :cols="7" target="q">No challans match.</x-ui.table-empty>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div style="padding:14px 18px;border-top:1px solid var(--border)">
                {{ $rows->links() }}
            </div>
        @endif
    </div>

    {{-- Server-rendered rather than x-if, for the reason given in
         partials/danger-dialog: the dialog must never show stale values. --}}
    @if ($editing)
        @php $collected = $editing->paidAmount(); @endphp
        <div class="drawer-backdrop" style="z-index:90" wire:click="closeEdit"></div>

        <div style="position:fixed;z-index:91;inset:0;display:flex;align-items:center;justify-content:center;padding:16px;pointer-events:none">
            <div class="card" style="width:100%;max-width:480px;max-height:100%;overflow:auto;pointer-events:auto;padding:0">

                <div style="display:flex;gap:13px;padding:20px 22px;border-bottom:1px solid var(--border);background:var(--surface2)">
                    <div style="display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:11px;flex:none;background:var(--due)">
                        <x-icon name="edit" :size="18" style="color:#fff" />
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:var(--fs-md);font-weight:800;color:var(--ink)">Edit {{ $editing->challan_no }}</div>
                        <div style="font-size:var(--fs-xs);color:var(--muted)">{{ $editing->student?->name }} · {{ $editing->subject() }}</div>
                    </div>
                </div>

                <div style="padding:20px 22px">
                    <div class="grid-3" style="gap:12px">
                        <div>
                            <label class="label">Course fee</label>
                            <input type="number" min="0" step="1" inputmode="numeric" wire:model.live.debounce.300ms="editBase" class="input tnum">
                        </div>
                        <div>
                            <label class="label">Discount</label>
                            <input type="number" min="0" step="1" inputmode="numeric" wire:model.live.debounce.300ms="editDiscount" class="input tnum">
                        </div>
                        <div>
                            <label class="label">Certificate</label>
                            <input type="number" min="0" step="1" inputmode="numeric" wire:model.live.debounce.300ms="editCertificate" class="input tnum">
                        </div>
                    </div>

                    <div style="display:flex;justify-content:space-between;gap:12px;margin-top:14px;padding:11px 14px;background:var(--surface3);border-radius:10px;font-size:var(--fs-sm)">
                        <span style="color:var(--muted)">Net payable</span>
                        <span class="tnum">
                            <span style="color:var(--faint);text-decoration:line-through">{{ Format::money($editing->net_amount) }}</span>
                            <span style="font-weight:800;color:var(--ink);margin-left:6px">{{ Format::money(max(0, $newNet)) }}</span>
                        </span>
                    </div>
                    {{-- For a collection typed wrong. Never edits a payment:
                         a rise is recorded as a correction payment, a cut as
                         a reversal, so the activity log shows both. --}}
                    <div class="grid-2" style="gap:12px;margin-top:14px">
                        <div>
                            <label class="label">Collected so far</label>
                            <input type="number" min="0" step="1" inputmode="numeric" wire:model.live.debounce.300ms="editCollected" class="input tnum">
                        </div>
                        <div>
                            <label class="label">Remaining</label>
                            <div class="input tnum" style="background:var(--surface3);font-weight:700;color:{{ $newNet - $newCollected < 0 ? 'var(--over)' : 'var(--ink)' }}">
                                {{ Format::money($newNet - $newCollected) }}
                            </div>
                        </div>
                    </div>
                    @if ($newCollected !== $collected)
                        <div style="font-size:var(--fs-xs);color:var(--muted);margin-top:8px">
                            {{ $newCollected > $collected
                                ? 'Records a '.Format::money($newCollected - $collected).' correction payment, dated with the last collection.'
                                : 'Records a '.Format::money($collected - $newCollected).' reversal against the latest payments.' }}
                            Was {{ Format::money($collected) }}.
                        </div>
                    @endif
                    @if ($editing->plan === 'split')
                        <div style="font-size:var(--fs-xs);color:var(--muted);margin-top:6px">
                            On an installment plan: the difference is added to, or taken off, the last installments.
                        </div>
                    @endif

                    <label class="label" style="margin-top:16px">Reason</label>
                    <textarea wire:model="editReason" class="input" rows="2" placeholder="Recorded in the activity log"
                              style="resize:vertical;min-height:62px"></textarea>

                    <label class="label" style="margin-top:14px">Your password</label>
                    <input wire:model="editSecret" type="password" class="input" placeholder="Re-enter your password" autocomplete="current-password">

                    @if ($editError)
                        <div style="display:flex;align-items:flex-start;gap:7px;font-size:var(--fs-xs);color:var(--over);margin-top:12px;line-height:1.5">
                            <x-icon name="alert-circle" :size="14" style="flex:none;margin-top:1px" /> {{ $editError }}
                        </div>
                    @endif
                </div>

                <div style="display:flex;gap:10px;padding:16px 22px;border-top:1px solid var(--border);background:var(--surface2)">
                    <button class="btn btn-ghost" style="flex:1" wire:click="closeEdit">Cancel</button>
                    <button class="btn btn-primary" style="flex:1" wire:click="saveEdit" wire:loading.attr="disabled">Save changes</button>
                </div>
            </div>
        </div>
    @endif
</div>
