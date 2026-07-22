<?php

use App\Models\AuditLog;
use App\Services\Analytics;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/** Platform overview, institute-wide figures no officer is permitted to see. */
new #[Layout('components.layouts.super')] class extends Component {
    public function with(): array
    {
        $analytics = app(Analytics::class);

        return [
            'ledger' => $analytics->ledger(),
            'counts' => $analytics->counts(),
            'security' => $analytics->securitySummary(),
            'months' => $analytics->monthlyRevenue(),
            'recent' => AuditLog::query()->latest('id')->limit(8)->get(),
        ];
    }
}; ?>

<div class="container-app anim-fade">

    {{-- Money ------------------------------------------------------------ --}}
    <div class="grid-3" style="margin-bottom:16px">
        @foreach ([
            ['Total billed', $ledger['billed'], $ledger['challans'].' challans', 'var(--navy2)'],
            ['Received', $ledger['received'], $ledger['billed'] > 0 ? round($ledger['received'] / $ledger['billed'] * 100).'% of billed' : '', 'var(--paid)'],
            ['Outstanding', $ledger['outstanding'], $counts['overdue'].' overdue', 'var(--due)'],
        ] as [$label, $value, $sub, $colour])
            <div class="card" style="padding:18px 20px">
                <div style="font-size:11.5px;font-weight:700;color:var(--faint);letter-spacing:.06em;text-transform:uppercase">{{ $label }}</div>
                <div class="tnum" style="font-size:27px;font-weight:800;color:{{ $colour }};margin:7px 0 3px;letter-spacing:-.02em">{{ Format::money($value) }}</div>
                <div class="tnum" style="font-size:12px;color:var(--muted)">{{ $sub }}</div>
            </div>
        @endforeach
    </div>

    {{-- Reconciliation ---------------------------------------------------- --}}
    <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--paid-bg);border:1px solid var(--paid-br);border-radius:12px;margin-bottom:22px">
        <x-icon name="check-circle" :size="17" style="color:var(--paid);flex:none" />
        <span class="tnum" style="font-size:12.5px;color:var(--paid);font-weight:600">
            Ledger reconciles: {{ Format::money($ledger['billed']) }} billed =
            {{ Format::money($ledger['received']) }} received + {{ Format::money($ledger['outstanding']) }} outstanding.
        </span>
    </div>

    {{-- Counts ------------------------------------------------------------ --}}
    <div class="grid-3" style="margin-bottom:22px">
        @foreach ([
            ['Students', $counts['students'], $counts['students_removed'] > 0 ? $counts['students_removed'].' removed' : 'none removed', 'students'],
            ['Active staff', $counts['staff'], trim(($counts['staff_inactive'] ?: '0').' inactive · '.($counts['staff_removed'] ?: '0').' removed'), 'staff'],
            ['Enrolments', $counts['admissions'], $counts['cancelled'].' cancelled', 'registrations'],
        ] as [$label, $value, $sub, $icon])
            <div class="card" style="padding:18px 20px;display:flex;align-items:center;gap:14px">
                <div style="display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;background:var(--iris-bg);flex:none">
                    <x-icon :name="$icon" :size="20" style="color:var(--iris)" />
                </div>
                <div style="min-width:0">
                    <div class="tnum" style="font-size:23px;font-weight:800;color:var(--ink);letter-spacing:-.02em">{{ number_format($value) }}</div>
                    <div style="font-size:12px;color:var(--muted)">{{ $label }} · {{ $sub }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="split" style="margin-bottom:22px">
        {{-- Revenue chart --}}
        <div class="panel">
            <div class="panel-head">
                <x-icon name="reports" :size="18" style="color:var(--navy2)" />
                <h3 class="panel-title">Revenue received · last 12 months</h3>
            </div>
            <div style="padding:20px">
                @php $peak = max(1, $months->max('total')); @endphp
                <div style="display:flex;align-items:flex-end;gap:7px;height:170px">
                    @foreach ($months as $m)
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:7px;height:100%;justify-content:flex-end">
                            <div class="tnum" style="font-size:10px;color:var(--faint);font-weight:700">
                                {{ $m->total > 0 ? round($m->total / 1000).'k' : '' }}
                            </div>
                            <div style="width:100%;border-radius:5px 5px 0 0;min-height:3px;background:{{ $m->total > 0 ? 'var(--navy2)' : 'var(--surface3)' }};height:{{ max(2, round($m->total / $peak * 100)) }}%"
                                 title="{{ $m->label }} {{ $m->year }}: {{ Format::money($m->total) }}"></div>
                            <div style="font-size:10.5px;color:var(--muted);font-weight:600">{{ $m->label }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Security --}}
        <div class="panel">
            <div class="panel-head">
                <x-icon name="shield" :size="18" style="color:var(--iris)" />
                <h3 class="panel-title">Security · last {{ $security['days'] }} days</h3>
            </div>
            <div style="padding:6px 20px 16px">
                @foreach ([
                    ['Successful sign-ins', $security['sign_ins'], 'var(--paid)'],
                    ['Failed sign-in attempts', $security['failed'], $security['failed'] > 10 ? 'var(--over)' : 'var(--ink)'],
                    ['Failed two-factor codes', $security['twofa_failed'], $security['twofa_failed'] > 5 ? 'var(--over)' : 'var(--ink)'],
                    ['Recovery codes used', $security['recovery_used'], $security['recovery_used'] > 0 ? 'var(--due)' : 'var(--ink)'],
                    ['Admin password resets', $security['admin_resets'], 'var(--ink)'],
                ] as [$label, $value, $colour])
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--border)">
                        <span style="font-size:13px;color:var(--ink2)">{{ $label }}</span>
                        <span class="tnum" style="font-size:15px;font-weight:800;color:{{ $colour }}">{{ number_format($value) }}</span>
                    </div>
                @endforeach
                <a href="{{ route('superadmin.activity') }}" wire:navigate
                   style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;margin-top:14px">
                    View full activity log <x-icon name="chevron-right" :size="14" />
                </a>
            </div>
        </div>
    </div>

    {{-- Recent activity --------------------------------------------------- --}}
    <div class="panel">
        <div class="panel-head">
            <x-icon name="activity" :size="18" style="color:var(--navy2)" />
            <h3 class="panel-title">Latest activity</h3>
        </div>
        <div class="scroll-x">
            <table class="table">
                <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Subject</th></tr></thead>
                <tbody>
                    @forelse ($recent as $row)
                        <tr>
                            <td class="tnum" style="color:var(--muted);white-space:nowrap">{{ $row->created_at?->diffForHumans(short: true) }}</td>
                            <td>{{ $row->actorLabel() }}</td>
                            <td style="font-weight:600;color:var(--ink)">{{ $row->action }}</td>
                            <td class="tnum" style="color:var(--muted)">{{ $row->subject_label ?: '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-state">Nothing recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
