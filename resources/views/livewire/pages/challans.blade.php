<?php

use App\Models\Admission;
use App\Models\Challan;
use App\Services\ChallanActions;
use App\Services\Ledger;
use App\Support\Concerns\CollectsPayments;
use App\Support\Concerns\GuardsDoubleSubmit;
use App\Support\Concerns\ReversesPayments;
use App\Support\Concerns\SchedulesInstallments;
use App\Support\Matcher;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;

new class extends Component {
    use CollectsPayments, GuardsDoubleSubmit, ReversesPayments, SchedulesInstallments;

    public string $q = '';

    /** all | paid | unpaid | overdue. Status was only reachable by typing it. */
    public string $state = 'all';

    public ?int $drawerId = null;
    public ?int $cancelAdmId = null;
    public string $cancelReason = '';
    public string $cancelError = '';

    public function mount(): void
    {
        if ($open = request()->query('open')) {
            $this->drawerId = (int) $open;
        }
    }

    public function select(int $id): void
    {
        $this->drawerId = $id;
    }

    public function closeDrawer(): void
    {
        $this->drawerId = null;
    }

    public function askCancel(int $admissionId): void
    {
        $this->cancelAdmId = $admissionId;
        $this->cancelReason = '';
        $this->cancelError = '';
    }

    public function confirmCancel(): void
    {
        $this->cancelError = '';
        // Same as confirmPay: the dialog is already gone, so this is a repeat
        // click rather than a permission problem.
        if ($this->cancelAdmId === null) {
            return;
        }
        if (! auth()->user()->can('registrations.create')) {
            abort(403);
        }
        $admission = Admission::visibleTo(auth()->user())->find($this->cancelAdmId);
        if (! $admission) {
            abort(404);
        }
        try {
            app(ChallanActions::class)->cancel($admission, auth()->user(), $this->cancelReason);
        } catch (\Throwable $e) {
            $this->cancelError = $e->getMessage();

            return;
        }
        $this->cancelAdmId = null;
        $this->drawerId = null;
        $this->dispatch('bbt-toast', tone: 'warn', title: 'Registration cancelled', msg: 'Soft-deleted and recoverable');
    }

    /** Supplies CollectsPayments; also the source for every list below. */
    protected function scopedChallans(): Builder
    {
        return app(Ledger::class)->scopedChallans(auth()->user());
    }

    public function with(): array
    {
        $user = auth()->user();
        $L = app(Ledger::class);
        $matcher = new Matcher($this->q);

        // `installments` is eager loaded because every row below calls
        // paymentState(), and on a split plan that consults the schedule to see
        // whether an earlier installment has been missed. Without this the
        // list fires one query per challan.
        $all = $this->scopedChallans()->with(['student', 'admission.student', 'admission.course', 'admissions.course', 'installments'])->get();

        // Counts come from the unfiltered set, so a chip always shows how many
        // it would reveal rather than how many survived the current filter.
        $counts = [
            'all' => $all->count(),
            'paid' => $all->where('status', 'paid')->count(),
            'unpaid' => $all->filter(fn (Challan $c) => $c->paymentState() === 'unpaid')->count(),
            'overdue' => $all->filter(fn (Challan $c) => $c->paymentState() === 'overdue')->count(),
            'installment' => $all->filter(fn (Challan $c) => $c->paymentState() === 'installment')->count(),
        ];

        $rows = $all
            ->when($this->state !== 'all', fn ($rows) => $rows->filter(
                fn (Challan $c) => $c->paymentState() === $this->state
            ))
            // Asked of the invoice, never of `admission->student`. A charge has
            // no admission, so reaching through it threw "Attempt to read
            // property student on null" and took the whole screen down with a
            // 500 — not for the charge's row, for everybody's.
            ->filter(function (Challan $c) use ($matcher) {
                $subject = $c->subject();
                $codes = $c->courseCodes();
                $name = $c->student->name;

                return $matcher->matches([
                    '_all' => "{$c->challan_no} {$name} {$subject} {$codes} {$c->status} {$c->paymentState()}",
                    'no' => $c->challan_no,
                    'name' => $name,
                    'course' => trim($subject.' '.$codes),
                    'status' => $c->paymentState(),
                ]);
            })
            ->sortByDesc(fn (Challan $c) => $c->challan_no)
            ->values();

        return [
            'canRevenue' => $user->can('revenue.view'),
            'canPay' => $user->can('challans.pay'),
            'canCancel' => $user->can('registrations.create'),
            // Supervisor only. Administrator holds it by default; Admission
            // Officer deliberately does not, because the control is that the
            // person who mistyped the amount is not the person who undoes it.
            'canReverse' => $user->can('payments.reverse'),
            // Same key the wizard already uses to agree a plan while raising
            // the challan; see SchedulesInstallments::plannableChallan().
            'canPlan' => $user->can('registrations.create'),
            'planChallan' => $this->planId ? $this->scopedChallans()->with('installments')->find($this->planId) : null,
            'billed' => $L->billed($user),
            'received' => $L->received($user),
            'outstanding' => $L->outstanding($user),
            'rows' => $rows,
            'counts' => $counts,
            'selected' => $this->drawerId
                // `payments.reversals` is eager loaded so the ledger block can
                // show each handover net of corrections without firing a query
                // per payment — Payment::netAmount() answers from the relation
                // when it is loaded.
                ? $this->scopedChallans()->with(['student', 'raiser', 'admission.student', 'admission.course.trainer', 'admission.cohort', 'admission.enroller', 'admissions.course', 'discountApprover', 'auditLogs.actor', 'installments', 'payments.receiver', 'payments.reversals.approver'])->find($this->drawerId)
                : null,
            'payChallan' => $this->payId ? $this->scopedChallans()->with(['student', 'admission.student', 'payments.reversals'])->find($this->payId) : null,
            'reversePayment' => $this->reverseId
                ? \App\Models\Payment::with(['reversals', 'challan.student'])
                    ->whereIn('challan_id', $this->scopedChallans()->select('challans.id'))
                    ->find($this->reverseId)
                : null,
        ];
    }
}; ?>

@php
    use App\Support\Format;
    $pill = fn ($s) => match ($s) {
        'paid' => ['paid', 'Paid'],
        'overdue' => ['overdue', 'Overdue'],
        'installment' => ['installment', 'Installment'],
        default => ['unpaid', 'Unpaid'],
    };
@endphp

<div class="container-app anim-fade">
    @if ($canRevenue)
        <div class="grid-3" style="margin-bottom:18px">
            <div class="kpi"><div class="kpi-label" style="margin-bottom:6px">BILLED</div><div class="kpi-num tnum">{{ Format::money($billed) }}</div></div>
            <div class="kpi"><div class="kpi-label" style="margin-bottom:6px">RECEIVED</div><div class="kpi-num tnum" style="color:var(--paid)">{{ Format::money($received) }}</div></div>
            <div class="kpi"><div class="kpi-label" style="margin-bottom:6px">OUTSTANDING</div><div class="kpi-num tnum" style="color:var(--due)">{{ Format::money($outstanding) }}</div></div>
        </div>
    @endif

    <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;flex-wrap:wrap">
        <div style="flex:1;min-width:150px"><span style="font-size:var(--fs-md);font-weight:700;color:var(--ink)">Fee challans</span> <span style="font-size:var(--fs-xs);color:var(--muted)">{{ $rows->count() }} shown</span></div>
        <div class="search" style="width:300px"><x-icon name="search" :size="15" /><input wire:model.live.debounce.200ms="q" class="input" placeholder="Search challan #, student, course, status…"></div>
        <x-ui.busy target="q" label="Searching…" />
    </div>

    {{-- Status was previously reachable only by typing it into the search box,
         which is not an affordance so much as a secret. --}}
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        @foreach (['all' => 'All', 'unpaid' => 'Unpaid', 'installment' => 'Installment', 'overdue' => 'Overdue', 'paid' => 'Paid'] as $key => $label)
            <button wire:click="$set('state', '{{ $key }}')"
                    class="btn btn-sm {{ $state === $key ? 'btn-primary' : 'btn-ghost' }}">
                {{ $label }}
                <span class="tnum" style="opacity:.65;margin-left:4px">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    <div class="panel scroll-x" wire:loading.class="is-busy" wire:target="q,state">
        <table class="table table-cards">
            <thead><tr>
                <th>Challan #</th><th>Student</th><th>Course</th><th>Due / Paid via</th><th class="right">Net</th><th>Status</th><th class="actions-col"></th>
            </tr></thead>
            <tbody>
                @forelse ($rows as $c)
                    @php [$tone, $label] = $pill($c->paymentState()); @endphp
                    <tr class="clickable" wire:click="select({{ $c->id }})" wire:key="ch-{{ $c->id }}">
                        <td class="tnum rec-id" data-label="Challan #" style="font-weight:700;color:var(--iris)">{{ $c->challan_no }}</td>
                        <td data-label="Student">
                            <div style="display:flex;align-items:center;gap:10px">
                                <x-ui.avatar :name="$c->student->name" :size="30" />
                                <div><div style="font-size:var(--fs-sm);font-weight:600;color:var(--ink)">{{ $c->student->name }}</div><div class="tnum rec-id" style="font-size:var(--fs-2xs);font-weight:700;color:var(--iris)">{{ $c->student->student_code }}</div></div>
                            </div>
                        </td>
                        <td data-label="Course"><span class="clamp-2" title="{{ $c->subject() }}">{{ $c->subject() }}</span></td>
                        <td class="tnum" data-label="Due / paid via" style="color:var(--muted)">{{-- An imported legacy balance can have no due date; Format::date() renders
                             NULL as '', which would print a bare "due " with nothing after it. --}}
                        {{ $c->isPaid() ? 'via '.$c->paid_via : ($c->due_date ? 'due '.Format::date($c->due_date) : 'no due date') }}</td>
                        <td class="right tnum" data-label="Net" style="font-weight:700">{{ Format::money($c->net_amount) }}</td>
                        <td data-label="Status"><x-ui.pill :tone="$tone" :dot="true">{{ $label }}</x-ui.pill></td>
                        <td class="right actions-col" data-label="Actions">
                            <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center">
                                @if ($canPay && ! $c->isPaid())
                                    <button class="btn btn-ghost btn-sm" wire:click.stop="askPay({{ $c->id }})">Mark paid</button>
                                @endif
                                {{-- The PDF used to be reachable only after opening the
                                     drawer, so the commonest action on the screen was
                                     also the least visible one. --}}
                                {{-- @click.stop, not wire:click.stop. A wire: directive with no
                                     expression compiles to an empty `$wire.` call, which throws
                                     `SyntaxError: Unexpected token '}'` before the modifier is
                                     ever applied — so the row's select() fired anyway and the
                                     drawer opened behind the PDF. Stopping propagation is a
                                     browser concern with no server round trip, so it is Alpine's
                                     job, not Livewire's. --}}
                                {{-- Points at `view`, not `pdf`. This said "View challan
                                     PDF" while handing you a file download, which is a
                                     different thing from viewing and the reason the
                                     button read as broken. --}}
                                <a class="btn-icon btn-icon-plain" href="{{ route('challans.view', $c) }}" target="_blank"
                                   @click.stop title="View &amp; print challan" aria-label="View and print challan">
                                    <x-icon name="eye" :size="16" />
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.table-empty :cols="7" target="q,state">
                        No challans match your filter.
                    </x-ui.table-empty>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('partials.challan-drawer')
</div>
