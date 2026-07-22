<?php

use App\Services\Ledger;
use App\Support\Clock;
use App\Support\Format;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $user = auth()->user();
        $L = app(Ledger::class);
        $canRevenue = $user->can('revenue.view');
        $canAll = $user->can('scope.all');

        // Revenue trend bar heights (relative to the max bar).
        $trend = $L->revenueTrend($user);
        $maxTrend = max(1, ...array_column($trend, 'amount'));
        $trend = array_map(fn ($m) => $m + ['h' => max(4, round($m['amount'] / $maxTrend * 100))], $trend);

        // Revenue-by-course bar widths.
        $byCourse = $L->revenueByCourse($user);
        $maxCourse = max(1, ...(array_column($byCourse, 'amount') ?: [1]));
        $byCourse = array_map(fn ($c) => $c + ['w' => max(3, round($c['amount'] / $maxCourse * 100))], $byCourse);

        // Needs attention: overdue (all scopes) + near-full courses (admin only).
        $attention = [];
        foreach ($L->overdueChallans($user) as $ch) {
            $attention[] = [
                'kind' => 'overdue', 'badge' => '!', 'bg' => 'var(--over-bg)', 'col' => 'var(--over)',
                'title' => $ch->admission->student->name,
                'sub' => $ch->challan_no.' · '.$ch->admission->course->title,
                'meta' => Format::money($ch->net_amount), 'metaColor' => 'var(--over)',
                'href' => route('challans', ['open' => $ch->id]),
            ];
        }
        foreach ($L->nearFullCourses($user) as $nf) {
            $attention[] = [
                'kind' => 'capacity', 'badge' => '▲', 'bg' => 'var(--due-bg)', 'col' => 'var(--due)',
                'title' => $nf['course']->title,
                'sub' => $nf['used'].' of '.$nf['course']->capacity.' seats filled',
                'meta' => $nf['left'].' left', 'metaColor' => 'var(--due)',
                'href' => route('courses'),
            ];
        }

        return [
            'canRevenue' => $canRevenue,
            'billed' => Format::money($L->billed($user)),
            'received' => Format::money($L->received($user)),
            'outstanding' => Format::money($L->outstanding($user)),
            'receivedPct' => $L->receivedPct($user).'%',
            'challanCount' => $L->challanCount($user),
            'activeStudents' => $L->activeStudentsCount($user),
            'studentsLabel' => $canAll ? 'Active students' : 'My active students',
            'regsThisMonth' => $L->regsThisMonth($user),
            'regsMonthLabel' => 'Registrations · '.Clock::today()->format('M'),
            'overdue' => $L->overdueCount($user),
            'trend' => $trend,
            'byCourse' => $byCourse,
            'attention' => $attention,
        ];
    }
}; ?>

<div class="container-app anim-fade">
    @if ($canRevenue)
        {{-- Revenue KPI row (spec §9.1) --}}
        <div class="grid-3" style="margin-bottom:16px">
            <div style="background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:16px;padding:20px 22px;color:#fff;box-shadow:var(--sh2)">
                <div style="font-size:12.5px;color:#b9bcdd;font-weight:600;letter-spacing:.02em;margin-bottom:8px">TOTAL BILLED</div>
                <div class="tnum" style="font-size:30px;font-weight:800;letter-spacing:-.02em">{{ $billed }}</div>
                <div style="font-size:12px;color:#9599c4;margin-top:6px">Net of {{ $challanCount }} challans issued</div>
            </div>
            <div class="card" style="padding:20px 22px">
                <div style="display:flex;align-items:center;gap:7px;margin-bottom:8px"><span style="width:9px;height:9px;border-radius:2px;background:var(--paid)"></span><span style="font-size:12.5px;color:var(--muted);font-weight:600;letter-spacing:.02em">RECEIVED</span></div>
                <div class="tnum" style="font-size:30px;font-weight:800;color:var(--paid);letter-spacing:-.02em">{{ $received }}</div>
                <div style="font-size:12px;color:var(--muted);margin-top:6px">{{ $receivedPct }} of billed collected</div>
            </div>
            <div class="card" style="padding:20px 22px">
                <div style="display:flex;align-items:center;gap:7px;margin-bottom:8px"><span style="width:9px;height:9px;border-radius:2px;background:var(--due)"></span><span style="font-size:12.5px;color:var(--muted);font-weight:600;letter-spacing:.02em">OUTSTANDING</span></div>
                <div class="tnum" style="font-size:30px;font-weight:800;color:var(--due);letter-spacing:-.02em">{{ $outstanding }}</div>
                <div style="font-size:12px;color:var(--muted);margin-top:6px">Billed − Received · reconciled</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;padding:11px 16px;background:var(--paid-bg);border:1px solid var(--paid-br);border-radius:12px;margin-bottom:22px">
            <x-icon name="check-circle" :size="17" style="color:var(--paid)" />
            <span class="tnum" style="font-size:13px;color:var(--paid);font-weight:500">Ledger reconciles: {{ $billed }} billed = {{ $received }} received + {{ $outstanding }} outstanding.</span>
        </div>
    @endif

    {{-- Ops KPI row (always) --}}
    <div class="grid-3" style="margin-bottom:22px">
        <div class="kpi" style="display:flex;align-items:center;gap:14px">
            <div style="width:42px;height:42px;flex:0 0 auto;border-radius:11px;background:var(--info-bg);display:flex;align-items:center;justify-content:center;color:var(--info)"><x-icon name="users" :size="21" /></div>
            <div><div class="kpi-num tnum">{{ $activeStudents }}</div><div class="kpi-label">{{ $studentsLabel }}</div></div>
        </div>
        <div class="kpi" style="display:flex;align-items:center;gap:14px">
            <div style="width:42px;height:42px;flex:0 0 auto;border-radius:11px;background:var(--iris-bg);display:flex;align-items:center;justify-content:center;color:var(--iris)"><x-icon name="spark" :size="21" /></div>
            <div><div class="kpi-num tnum">{{ $regsThisMonth }}</div><div class="kpi-label">{{ $regsMonthLabel }}</div></div>
        </div>
        <div class="kpi" style="display:flex;align-items:center;gap:14px">
            <div style="width:42px;height:42px;flex:0 0 auto;border-radius:11px;background:var(--over-bg);display:flex;align-items:center;justify-content:center;color:var(--over)"><x-icon name="alert" :size="21" /></div>
            <div><div class="kpi-num tnum">{{ $overdue }}</div><div class="kpi-label">Overdue challans</div></div>
        </div>
    </div>

    @if ($canRevenue)
        {{-- Charts --}}
        <div class="split" style="margin-bottom:22px">
            <div class="card" style="padding:20px 22px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px"><h3 class="panel-title">Revenue received</h3><span style="font-size:12px;color:var(--muted);background:var(--surface3);padding:4px 10px;border-radius:8px">2026 · monthly</span></div>
                <div style="display:flex;align-items:flex-end;gap:14px;height:170px;padding-top:10px">
                    @foreach ($trend as $m)
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:8px;height:100%;justify-content:flex-end">
                            <div class="bar" style="width:100%;max-width:34px;height:{{ $m['h'] }}%;border-radius:7px 7px 0 0;background:linear-gradient(180deg,var(--orange2),var(--orange));transform-origin:bottom;animation:bbtBar .5s ease-out" title="{{ Format::money($m['amount']) }}"></div>
                            <div style="font-size:11px;color:var(--faint);font-weight:600">{{ $m['mon'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="card" style="padding:20px 22px">
                <h3 class="panel-title" style="margin-bottom:18px">Revenue by course</h3>
                <div style="display:flex;flex-direction:column;gap:14px">
                    @forelse ($byCourse as $c)
                        <div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:6px"><span style="font-size:12.5px;color:var(--ink2);font-weight:500">{{ $c['title'] }}</span><span class="tnum" style="font-size:12.5px;color:var(--muted);font-weight:600">{{ Format::money($c['amount']) }}</span></div>
                            <div class="bar"><span style="width:{{ $c['w'] }}%;background:linear-gradient(90deg,var(--navy2),var(--navy))"></span></div>
                        </div>
                    @empty
                        <div style="font-size:13px;color:var(--faint)">No revenue recorded yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- Needs attention --}}
    <div class="panel">
        <div class="panel-head"><x-icon name="alert" :size="18" style="color:var(--orange)" /><h3 class="panel-title">Needs attention</h3></div>
        @forelse ($attention as $a)
            <a href="{{ $a['href'] }}" wire:navigate style="display:flex;align-items:center;gap:14px;padding:14px 22px;border-bottom:1px solid var(--surface3);cursor:pointer" onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">
                <div style="width:34px;height:34px;flex:0 0 auto;border-radius:9px;background:{{ $a['bg'] }};display:flex;align-items:center;justify-content:center;color:{{ $a['col'] }};font-size:15px;font-weight:800">{{ $a['badge'] }}</div>
                <div style="flex:1;min-width:0"><div style="font-size:13.5px;font-weight:600;color:var(--ink)">{{ $a['title'] }}</div><div style="font-size:12px;color:var(--muted)">{{ $a['sub'] }}</div></div>
                <div class="tnum" style="font-size:13px;font-weight:700;color:{{ $a['metaColor'] }}">{{ $a['meta'] }}</div>
                <x-icon name="chevron-right" :size="16" style="color:var(--faint)" />
            </a>
        @empty
            <div class="empty-state">Nothing needs attention right now.</div>
        @endforelse
    </div>
</div>
