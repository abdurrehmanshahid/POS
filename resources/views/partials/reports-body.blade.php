{{-- The Reports screen, shared by the staff page and the super admin's tab.
     Needs the screen() data from Reporting, plus `period`, `selectedKey`,
     `presets`, `from`, `to` and `exportRoute` (the route the Export links hit). --}}
@php use App\Support\Format; @endphp
<div class="container-app anim-fade">

    {{-- Toolbar: one row, one baseline, filters left and the action right. --}}
    <div class="toolbar">
        <div class="segmented">
            @foreach ($presets as $key => $label)
                <button wire:click="setPeriod('{{ $key }}')" class="{{ $selectedKey === $key ? 'on' : '' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($selectedKey === 'custom')
            <input type="date" wire:model.live="from" class="input" style="width:156px;height:42px">
            <span style="font-size:var(--fs-xs);color:var(--muted)">to</span>
            <input type="date" wire:model.live="to" class="input" style="width:156px;height:42px">
        @endif

        {{-- Every period button and both date boxes recompute the whole ledger
             over 445 challans and 471 payments, so this one answers for the
             range as a whole rather than for a single control. --}}
        <x-ui.busy target="from,to,setPeriod" label="Recalculating…" />

        <div class="toolbar-grow"></div>

        <span class="tnum" style="font-size:var(--fs-xs);color:var(--muted);white-space:nowrap">{{ $period->rangeLabel() }}</span>

        {{-- Three formats, one report. Links rather than wire:click, because a
             browser only saves a file from a real navigation. The period the
             screen is showing rides along in the query string, so the file and
             the figures above it always cover the same window. --}}
        @php
            $exportParams = array_filter(['period' => $selectedKey, 'from' => $from, 'to' => $to]);
        @endphp
        <div class="segmented">
            <span class="segmented-label"><x-icon name="download" :size="14" /> Export</span>
            <a href="{{ route($exportRoute, $exportParams + ['format' => 'csv']) }}"
               title="One CSV file, all sections stacked">CSV</a>
            <a href="{{ route($exportRoute, $exportParams + ['format' => 'zip']) }}"
               title="A zip holding one CSV per section">CSV (zip)</a>
            <a href="{{ route($exportRoute, $exportParams + ['format' => 'xlsx']) }}"
               title="Excel workbook, one sheet per section">Excel</a>
        </div>
    </div>

    @if ($canSeeMoney)
        {{-- Headline figures, including the per-day metric ----------------- --}}
        <div class="grid-3" style="margin-bottom:16px">
            @foreach ([
                ['Collected', $summary['collected'], $summary['payments'].' payment'.($summary['payments'] === 1 ? '' : 's'), 'var(--paid)'],
                ['Average per day', $summary['per_day'], 'across '.$summary['days'].' day'.($summary['days'] === 1 ? '' : 's'), 'var(--navy2)'],
                ['Collected today', $summary['today'], Format::date($today), 'var(--iris)'],
            ] as [$label, $value, $sub, $colour])
                <div class="card" style="padding:18px 20px">
                    <div style="font-size:var(--fs-2xs);font-weight:700;color:var(--faint);letter-spacing:.06em;text-transform:uppercase">{{ $label }}</div>
                    <div class="tnum" style="font-size:var(--fs-2xl);font-weight:800;color:{{ $colour }};margin:7px 0 3px;letter-spacing:-.02em">{{ Format::money($value) }}</div>
                    <div class="tnum" style="font-size:var(--fs-xs);color:var(--muted)">{{ $sub }}</div>
                </div>
            @endforeach
        </div>

        <div class="split" style="margin-bottom:22px" wire:loading.class="is-busy" wire:target="from,to,setPeriod">
            {{-- Daily collections --------------------------------------- --}}
            <div class="panel">
                <div class="panel-head">
                    <x-icon name="reports" :size="18" style="color:var(--navy2)" />
                    <h3 class="panel-title">Collections by {{ $period->granularity() }}</h3>
                    <span class="tnum" style="margin-left:auto;font-size:var(--fs-xs);color:var(--muted)">{{ Format::money($summary['collected']) }} total</span>
                </div>
                <div style="padding:20px">
                    @if ($series->sum('total') > 0)
                        <div style="display:flex;align-items:flex-end;gap:4px;height:180px">
                            @foreach ($series as $b)
                                <div style="flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end"
                                     title="{{ $b->label }} {{ $b->sub }}: {{ Format::money($b->total) }}">
                                    @if ($b->total > 0)
                                        <div class="tnum" style="font-size:var(--fs-3xs);color:var(--faint);font-weight:700;white-space:nowrap">{{ round($b->total / 1000) }}k</div>
                                    @endif
                                    <div style="width:100%;border-radius:4px 4px 0 0;min-height:3px;background:{{ $b->total > 0 ? 'var(--navy2)' : 'var(--surface3)' }};height:{{ max(2, round($b->total / $seriesPeak * 100)) }}%"></div>
                                    @if ($series->count() <= 31)
                                        <div class="tnum" style="font-size:var(--fs-3xs);color:var(--muted);font-weight:600">{{ $b->label }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="empty-state">No payments were recorded in this period.</div>
                    @endif
                </div>
            </div>

            {{-- Payment methods ------------------------------------------ --}}
            <div class="panel">
                <div class="panel-head">
                    <x-icon name="challans" :size="18" style="color:var(--navy2)" />
                    <h3 class="panel-title">By payment method</h3>
                </div>
                <div style="padding:6px 20px 16px">
                    @forelse ($methods as $m)
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)">
                            <div style="min-width:0">
                                <div style="font-size:var(--fs-sm);font-weight:600;color:var(--ink)">{{ $m->method }}</div>
                                <div class="tnum" style="font-size:var(--fs-2xs);color:var(--muted)">{{ $m->count }} payment{{ $m->count === 1 ? '' : 's' }}</div>
                            </div>
                            <span class="tnum" style="font-size:var(--fs-base);font-weight:800;color:var(--ink)">{{ Format::money($m->total) }}</span>
                        </div>
                    @empty
                        <div class="empty-state">Nothing collected in this period.</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Revenue by course ---------------------------------------------- --}}
        <div class="panel" style="margin-bottom:22px">
            <div class="panel-head">
                <x-icon name="courses" :size="18" style="color:var(--navy2)" />
                <h3 class="panel-title">Revenue by course</h3>
            </div>
            <div style="padding:18px 20px">
                @forelse ($courses as $c)
                    <div style="margin-bottom:15px">
                        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:6px">
                            <span style="font-size:var(--fs-sm);font-weight:600;color:var(--ink);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                {{ $c->title }}
                                <span class="tnum" style="color:var(--muted);font-weight:500">· {{ $c->code }} · {{ $c->enrolments }} paid</span>
                            </span>
                            <span class="tnum" style="font-size:var(--fs-sm);font-weight:800;color:var(--ink);flex:none">{{ Format::money($c->total) }}</span>
                        </div>
                        <div style="height:9px;border-radius:5px;background:var(--surface3);overflow:hidden">
                            <div style="height:100%;border-radius:5px;background:var(--navy2);width:{{ max(1, round($c->total / $coursePeak * 100)) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="empty-state">No course revenue in this period.</div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Officer performance ------------------------------------------------ --}}
    @if ($officers->isNotEmpty())
        <div class="panel" style="margin-bottom:22px">
            <div class="panel-head">
                <x-icon name="trending-up" :size="18" style="color:var(--navy2)" />
                <h3 class="panel-title">Officer performance</h3>
                <span style="margin-left:auto;font-size:var(--fs-xs);color:var(--muted)">{{ $period->label() }}</span>
            </div>
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr><th>Officer</th><th>Enrolments</th><th>Billed</th><th>Collected</th><th>Collection rate</th><th>Avg discount</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($officers as $o)
                            @php
                                $rate = $o->collection_rate;
                                $rateColour = $rate === null ? 'var(--faint)'
                                    : ($rate >= 80 ? 'var(--paid)' : ($rate >= 50 ? 'var(--due)' : 'var(--over)'));
                            @endphp
                            <tr @style(['opacity:.5' => $o->is_removed])>
                                <td>
                                    <div style="display:flex;align-items:center;gap:11px">
                                        <x-ui.avatar :name="$o->name" :variant="$o->role_id === 'admin' ? 'navy' : 'orange'" :size="32" />
                                        <div style="min-width:0">
                                            <div style="font-weight:600;color:var(--ink)">{{ $o->name }}</div>
                                            <div class="tnum" style="font-size:var(--fs-xs);color:var(--muted)">{{ $o->username }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="tnum" style="font-weight:700">{{ $o->enrolments }}</td>
                                <td class="tnum">{{ Format::money($o->billed) }}</td>
                                <td class="tnum" style="font-weight:700;color:var(--paid)">{{ Format::money($o->received) }}</td>
                                <td>
                                    @if ($rate === null)
                                        <span style="font-size:var(--fs-xs);color:var(--faint)">no billing</span>
                                    @else
                                        <div style="display:flex;align-items:center;gap:9px;min-width:120px">
                                            <div style="flex:1;height:6px;border-radius:3px;background:var(--surface3);overflow:hidden">
                                                <div style="height:100%;border-radius:3px;width:{{ $rate }}%;background:{{ $rateColour }}"></div>
                                            </div>
                                            <span class="tnum" style="font-size:var(--fs-xs);font-weight:800;color:{{ $rateColour }}">{{ $rate }}%</span>
                                        </div>
                                    @endif
                                </td>
                                <td class="tnum" style="color:var(--muted)">{{ $o->avg_discount > 0 ? Format::money($o->avg_discount) : 'none' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Outstanding dues, with ageing --------------------------------------- --}}
    <div class="panel">
        <div class="panel-head">
            <x-icon name="alert" :size="18" style="color:var(--due)" />
            <h3 class="panel-title">Outstanding dues</h3>
            <span class="tnum" style="margin-left:auto;font-size:var(--fs-xs);color:var(--muted)">
                {{ Format::money($dues['total']) }} owed in total
            </span>
        </div>

        {{-- Ageing buckets. Deliberately NOT filtered by the period: money owed
             since March is still owed today, and hiding it because the filter
             says "this month" is how bad debt goes unnoticed. --}}
        <div class="ageing-row">
            @foreach ($dues['buckets'] as $b)
                <div style="background:var(--surface);padding:14px 16px">
                    <div style="font-size:var(--fs-2xs);font-weight:700;color:var(--faint);letter-spacing:.05em;text-transform:uppercase">{{ $b['label'] }}</div>
                    <div class="tnum" style="font-size:var(--fs-lg);font-weight:800;margin-top:5px;color:{{ $b['total'] > 0 ? ($b['tone'] === 'overdue' ? 'var(--over)' : ($b['tone'] === 'unpaid' ? 'var(--due)' : 'var(--ink)')) : 'var(--faint)' }}">
                        {{ Format::money($b['total']) }}
                    </div>
                    <div class="tnum" style="font-size:var(--fs-2xs);color:var(--muted);margin-top:2px">{{ $b['count'] }} challan{{ $b['count'] === 1 ? '' : 's' }}</div>
                </div>
            @endforeach
        </div>

        <div class="scroll-x">
            <table class="table">
                <thead>
                    <tr><th>Student</th><th>Courses</th><th>Overdue by</th><th class="right">Owes</th></tr>
                </thead>
                <tbody>
                    @forelse ($dues['students'] as $s)
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:11px">
                                    <x-ui.avatar :name="$s->name" variant="orange" :size="30" />
                                    <div style="min-width:0">
                                        <div style="font-weight:600;color:var(--ink)">{{ $s->name }}</div>
                                        <div class="tnum" style="font-size:var(--fs-xs);color:var(--iris);font-weight:700">{{ $s->code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td style="color:var(--muted);font-size:var(--fs-xs)">{{ $s->courses }}</td>
                            <td>
                                @if ($s->days_late > 0)
                                    <x-ui.pill :tone="$s->days_late > 60 ? 'overdue' : 'unpaid'" :dot="true">
                                        {{ $s->days_late }} day{{ $s->days_late === 1 ? '' : 's' }}
                                    </x-ui.pill>
                                @else
                                    <span style="font-size:var(--fs-xs);color:var(--muted)">not yet due</span>
                                @endif
                            </td>
                            <td class="right tnum" style="font-weight:800;color:var(--ink);white-space:nowrap">{{ Format::money($s->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-state">Every challan in scope is settled.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
