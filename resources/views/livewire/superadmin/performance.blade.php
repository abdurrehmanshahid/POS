<?php

use App\Services\Analytics;
use App\Support\Clock;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Staff performance, who is enrolling, and who is actually collecting.
 *
 * The column that matters is `collection rate`. Counting enrolments alone
 * rewards whoever signs the most forms; an officer who enrols heavily and never
 * follows up on payment is manufacturing outstanding debt, and looks like a top
 * performer until you divide received by billed.
 */
new #[Layout('components.layouts.super')] class extends Component {
    /** all | year | quarter | month */
    public string $period = 'all';

    public function with(): array
    {
        $since = match ($this->period) {
            'month' => Clock::today()->copy()->startOfMonth(),
            'quarter' => Clock::today()->copy()->startOfQuarter(),
            'year' => Clock::today()->copy()->startOfYear(),
            default => null,
        };

        $analytics = app(Analytics::class);
        $staff = $analytics->staffPerformance($since);

        return [
            'staff' => $staff,
            'courses' => $analytics->revenueByCourse(),
            'topBilled' => max(1, $staff->max('billed') ?: 1),
        ];
    }
}; ?>

<div class="container-app anim-fade">

    {{-- Period --}}
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;flex-wrap:wrap">
        <span style="font-size:var(--fs-xs);color:var(--muted);font-weight:600">Period</span>
        <div style="display:inline-flex;background:var(--surface3);border-radius:11px;padding:4px;gap:2px">
            @foreach (['all' => 'All time', 'year' => 'This year', 'quarter' => 'This quarter', 'month' => 'This month'] as $key => $label)
                <button wire:click="$set('period','{{ $key }}')"
                        class="btn btn-sm {{ $period === $key ? 'btn-primary' : '' }}"
                        style="{{ $period === $key ? '' : 'background:transparent;color:var(--ink2)' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Scorecards --}}
    <div class="panel" style="margin-bottom:22px">
        <div class="panel-head">
            <x-icon name="trending-up" :size="18" style="color:var(--navy2)" />
            <h3 class="panel-title">Officer scorecards</h3>
        </div>
        <div class="scroll-x">
            <table class="table">
                <thead>
                    <tr>
                        <th>Officer</th><th>Enrolments</th><th>Students</th>
                        <th>Billed</th><th>Received</th><th>Collection</th>
                        <th>Avg discount</th><th>Overdue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($staff as $s)
                        @php
                            $rate = $s->collection_rate;
                            $rateColour = $rate === null ? 'var(--faint)'
                                : ($rate >= 80 ? 'var(--paid)' : ($rate >= 50 ? 'var(--due)' : 'var(--over)'));
                        @endphp
                        <tr @style(['opacity:.5' => $s->is_removed])>
                            <td>
                                <div style="display:flex;align-items:center;gap:11px">
                                    <x-ui.avatar :name="$s->name" :variant="$s->role_id === 'admin' ? 'navy' : 'orange'" :size="32" />
                                    <div style="min-width:0">
                                        <div style="font-weight:600;color:var(--ink)">{{ $s->name }}</div>
                                        <div class="tnum" style="font-size:var(--fs-xs);color:var(--muted)">
                                            {{ $s->username }}{{ $s->is_removed ? ' · removed' : '' }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="tnum" style="font-weight:700">{{ number_format($s->enrolments) }}</td>
                            <td class="tnum">{{ number_format($s->students) }}</td>
                            <td class="tnum">{{ Format::money($s->billed) }}</td>
                            <td class="tnum" style="font-weight:700;color:var(--paid)">{{ Format::money($s->received) }}</td>
                            <td>
                                @if ($rate === null)
                                    <span style="font-size:var(--fs-xs);color:var(--faint)">-</span>
                                @else
                                    <div style="display:flex;align-items:center;gap:9px;min-width:120px">
                                        <div style="flex:1;height:6px;border-radius:3px;background:var(--surface3);overflow:hidden">
                                            <div style="height:100%;border-radius:3px;width:{{ $rate }}%;background:{{ $rateColour }}"></div>
                                        </div>
                                        <span class="tnum" style="font-size:var(--fs-xs);font-weight:800;color:{{ $rateColour }}">{{ $rate }}%</span>
                                    </div>
                                @endif
                            </td>
                            <td class="tnum" style="color:var(--muted)">{{ $s->avg_discount > 0 ? Format::money($s->avg_discount) : '' }}</td>
                            <td class="tnum" style="font-weight:700;color:{{ $s->overdue > 0 ? 'var(--over)' : 'var(--muted)' }}">
                                {{ $s->overdue ?: '' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty-state">No activity in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Revenue by course --}}
    <div class="panel">
        <div class="panel-head">
            <x-icon name="courses" :size="18" style="color:var(--navy2)" />
            <h3 class="panel-title">Revenue by course</h3>
        </div>
        <div style="padding:18px 20px">
            @php $peak = max(1, $courses->max('received') ?: 1); @endphp
            @forelse ($courses as $c)
                <div style="margin-bottom:15px">
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:6px">
                        <span style="font-size:var(--fs-sm);font-weight:600;color:var(--ink);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            {{ $c->title }}
                            <span class="tnum" style="color:var(--muted);font-weight:500">· {{ $c->code }} · {{ $c->enrolments }} enrolled</span>
                        </span>
                        <span class="tnum" style="font-size:var(--fs-sm);font-weight:800;color:var(--ink);flex:none">{{ Format::money($c->received) }}</span>
                    </div>
                    <div style="height:9px;border-radius:5px;background:var(--surface3);overflow:hidden">
                        <div style="height:100%;border-radius:5px;background:var(--navy2);width:{{ max(1, round($c->received / $peak * 100)) }}%"></div>
                    </div>
                </div>
            @empty
                <div class="empty-state">No revenue recorded yet.</div>
            @endforelse
        </div>
    </div>
</div>
