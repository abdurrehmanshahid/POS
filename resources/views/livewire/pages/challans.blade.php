<?php

use App\Models\Admission;
use App\Models\Challan;
use App\Services\ChallanActions;
use App\Services\Ledger;
use App\Support\Matcher;
use Livewire\Volt\Component;

new class extends Component {
    public string $q = '';
    public ?int $drawerId = null;
    public ?int $payId = null;
    public string $payMethod = '';
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

    public function askPay(int $id): void
    {
        $this->payId = $id;
        $this->payMethod = '';
    }

    public function confirmPay(): void
    {
        $challan = $this->scoped()->find($this->payId);
        if (! $challan || ! auth()->user()->can('challans.pay')) {
            abort(403);
        }
        try {
            app(ChallanActions::class)->markPaid($challan, auth()->user(), $this->payMethod);
        } catch (\Throwable $e) {
            $this->dispatch('bbt-toast', tone: 'err', title: 'Could not record payment', msg: $e->getMessage());

            return;
        }
        $this->payId = null;
        $this->dispatch('bbt-toast', tone: 'ok', title: 'Payment recorded', msg: $challan->challan_no.' · '.$this->payMethod);
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

    private function scoped()
    {
        return app(Ledger::class)->scopedChallans(auth()->user());
    }

    public function with(): array
    {
        $user = auth()->user();
        $L = app(Ledger::class);
        $matcher = new Matcher($this->q);

        $rows = $this->scoped()
            ->with(['admission.student', 'admission.course'])
            ->get()
            ->filter(function (Challan $c) use ($matcher) {
                $a = $c->admission;

                return $matcher->matches([
                    '_all' => "{$c->challan_no} {$a->student->name} {$a->course->title} {$a->course->code} {$c->status} {$c->paymentState()}",
                    'no' => $c->challan_no,
                    'name' => $a->student->name,
                    'course' => $a->course->title.' '.$a->course->code,
                    'status' => $c->paymentState(),
                ]);
            })
            ->sortByDesc(fn (Challan $c) => $c->challan_no)
            ->values();

        return [
            'canRevenue' => $user->can('revenue.view'),
            'canPay' => $user->can('challans.pay'),
            'canCancel' => $user->can('registrations.create'),
            'billed' => $L->billed($user),
            'received' => $L->received($user),
            'outstanding' => $L->outstanding($user),
            'rows' => $rows,
            'selected' => $this->drawerId
                ? $this->scoped()->with(['admission.student', 'admission.course.trainer', 'admission.enroller', 'discountApprover', 'auditLogs.actor', 'installments'])->find($this->drawerId)
                : null,
            'payChallan' => $this->payId ? $this->scoped()->with('admission.student')->find($this->payId) : null,
        ];
    }
}; ?>

@php
    use App\Support\Format;
    $pill = fn ($s) => match ($s) {
        'paid' => ['paid', 'Paid'],
        'overdue' => ['overdue', 'Overdue'],
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

    <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
        <div style="flex:1"><span style="font-size:15px;font-weight:700;color:var(--ink)">Fee challans</span> <span style="font-size:12.5px;color:var(--muted)">{{ $rows->count() }} shown</span></div>
        <div class="search" style="width:300px"><x-icon name="search" :size="15" /><input wire:model.live.debounce.200ms="q" class="input" placeholder="Search challan #, student, course, status…"></div>
    </div>

    <div class="panel scroll-x">
        <table class="table">
            <thead><tr>
                <th>Challan #</th><th>Student</th><th>Course</th><th>Due / Paid via</th><th class="right">Net</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
                @forelse ($rows as $c)
                    @php [$tone, $label] = $pill($c->paymentState()); @endphp
                    <tr class="clickable" wire:click="select({{ $c->id }})" wire:key="ch-{{ $c->id }}">
                        <td class="tnum" style="font-weight:700;color:var(--iris)">{{ $c->challan_no }}</td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <x-ui.avatar :name="$c->admission->student->name" :size="30" />
                                <div><div style="font-size:13.5px;font-weight:600;color:var(--ink)">{{ $c->admission->student->name }}</div><div class="tnum" style="font-size:11px;font-weight:700;color:var(--iris)">{{ $c->admission->student->student_code }}</div></div>
                            </div>
                        </td>
                        <td>{{ $c->admission->course->title }}</td>
                        <td class="tnum" style="color:var(--muted)">{{ $c->isPaid() ? 'via '.$c->paid_via : 'due '.Format::date($c->due_date) }}</td>
                        <td class="right tnum" style="font-weight:700">{{ Format::money($c->net_amount) }}</td>
                        <td><x-ui.pill :tone="$tone" :dot="true">{{ $label }}</x-ui.pill></td>
                        <td class="right">
                            @if ($canPay && ! $c->isPaid())
                                <button class="btn btn-ghost btn-sm" wire:click.stop="askPay({{ $c->id }})">Mark paid</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-state">No challans match your filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('partials.challan-drawer')
</div>
