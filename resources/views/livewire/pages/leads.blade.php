<?php

use App\Models\Lead;
use App\Support\Format;
use Livewire\Volt\Component;

/**
 * Leads: enquiries and where each one stands.
 *
 * One tab per step of the follow-up. A lead lands on Initial (Unread) and is
 * moved along by hand; the last two tabs each hold two outcomes.
 */
new class extends Component {
    public string $tab = 'unread';

    public string $q = '';

    // ---- Add form -----------------------------------------------------------
    public bool $formOpen = false;

    public string $fName = '';

    public string $fPhone = '';

    public string $fEmail = '';

    public string $fCourse = '';

    public string $fNotes = '';

    public string $formError = '';

    public function setTab(string $tab): void
    {
        if (isset(Lead::TABS[$tab])) {
            $this->tab = $tab;
        }
    }

    public function newLead(): void
    {
        abort_unless(auth()->user()->can('leads.manage'), 403);
        $this->reset('fName', 'fPhone', 'fEmail', 'fCourse', 'fNotes', 'formError');
        $this->formOpen = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('leads.manage'), 403);

        if (trim($this->fName) === '') {
            $this->formError = 'A lead needs a name.';

            return;
        }

        $name = trim($this->fName);

        Lead::create([
            'name' => $name,
            'phone' => trim($this->fPhone) ?: null,
            'email' => trim($this->fEmail) ?: null,
            'course_interest' => trim($this->fCourse) ?: null,
            'notes' => trim($this->fNotes) ?: null,
            'source' => 'manual',
            'stage' => 'unread',
            'stage_changed_at' => now(),
            'created_by' => auth()->id(),
        ]);

        $this->formOpen = false;
        $this->tab = 'unread';
        $this->dispatch('bbt-toast', tone: 'ok', title: 'Lead added', msg: $name.' is in Initial (Unread).');
    }

    public function move(int $id, string $stage): void
    {
        abort_unless(auth()->user()->can('leads.manage'), 403);
        abort_unless(isset(Lead::STAGES[$stage]), 422);

        $lead = Lead::findOrFail($id);
        if ($lead->stage === $stage) {
            return;
        }
        $lead->update(['stage' => $stage, 'stage_changed_at' => now()]);

        $this->dispatch('bbt-toast', tone: 'ok', title: $lead->name, msg: 'Moved to '.Lead::STAGES[$stage].'.');
    }

    public function with(): array
    {
        $term = trim($this->q);

        $counts = Lead::query()->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');
        $tabCounts = collect(Lead::TABS)->map(fn ($t) => (int) $counts->only($t[1])->sum());

        $leads = Lead::whereIn('stage', Lead::TABS[$this->tab][1])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('phone', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')
                ->orWhere('course_interest', 'like', '%'.$term.'%')))
            ->latest()
            ->get();

        return ['leads' => $leads, 'tabCounts' => $tabCounts, 'total' => (int) $counts->sum()];
    }
}; ?>

<div class="container-app anim-fade">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px">
        <div>
            <h1 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);margin:0;letter-spacing:-.01em">Leads</h1>
            <div class="tnum" style="font-size:var(--fs-sm);color:var(--muted);margin-top:3px">{{ $total }} total</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div class="search" style="width:300px;max-width:100%">
                <x-icon name="search" :size="15" />
                <input wire:model.live.debounce.200ms="q" placeholder="Search name, phone, course…" class="input">
            </div>
            <x-ui.busy target="q" label="Searching…" />
            <button class="btn btn-accent" wire:click="newLead"><x-icon name="plus" :size="16" />Add lead</button>
        </div>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        @foreach (Lead::TABS as $key => [$label])
            <button wire:click="setTab('{{ $key }}')"
                    class="btn btn-sm {{ $tab === $key ? 'btn-primary' : 'btn-ghost' }}">
                {{ $label }}
                <span class="tnum" style="opacity:.65;margin-left:4px">{{ $tabCounts[$key] }}</span>
            </button>
        @endforeach
    </div>

    <div class="panel" wire:loading.class="is-busy" wire:target="q,setTab">
        @if ($leads->isEmpty())
            <div class="empty-state" style="padding:40px">
                {{ $q !== '' ? 'No leads match that search.' : 'No leads in '.Lead::TABS[$tab][0].'.' }}
            </div>
        @else
            <table class="table table-cards">
                <thead>
                    <tr>
                        <th>Lead</th>
                        <th>Contact</th>
                        <th>Interested in</th>
                        <th>Source</th>
                        <th>Received</th>
                        <th>Stage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($leads as $lead)
                        <tr wire:key="lead-{{ $lead->id }}">
                            <td>
                                <div style="font-weight:700;color:var(--ink)">{{ $lead->name }}</div>
                                @if ($lead->notes)
                                    <div style="font-size:var(--fs-2xs);color:var(--muted);margin-top:2px">{{ \Illuminate\Support\Str::limit($lead->notes, 70) }}</div>
                                @endif
                            </td>
                            <td class="tnum">
                                {{ $lead->phone ?? '—' }}
                                @if ($lead->email)<div style="font-size:var(--fs-2xs);color:var(--muted)">{{ $lead->email }}</div>@endif
                            </td>
                            <td>{{ $lead->course_interest ?? '—' }}</td>
                            <td><x-ui.pill tone="iris">{{ ucfirst($lead->source) }}</x-ui.pill></td>
                            <td class="tnum">{{ Format::date($lead->created_at) }}</td>
                            <td>
                                <select class="select" wire:change="move({{ $lead->id }}, $event.target.value)" style="min-width:160px">
                                    @foreach (Lead::STAGES as $key => $label)
                                        <option value="{{ $key }}" @selected($lead->stage === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div x-data="{ open: @entangle('formOpen') }">
        <template x-if="open">
            <div>
                <div class="drawer-backdrop" @click="open=false"></div>
                <div class="drawer">
                    <div class="drawer-head">
                        <div class="drawer-head-icon"><x-icon name="users" :size="19" /></div>
                        <div class="drawer-head-text">
                            <div class="drawer-head-title">Add lead</div>
                            <div class="drawer-head-sub">It starts in Initial (Unread).</div>
                        </div>
                        <button class="btn-icon" @click="open=false"><x-icon name="x" :size="18" /></button>
                    </div>

                    <div class="drawer-body">
                        @if ($formError)
                            <div style="padding:11px 14px;background:var(--over-bg);border:1px solid var(--over-br);border-radius:11px;font-size:var(--fs-xs);color:var(--over);font-weight:600;margin-bottom:16px">
                                {{ $formError }}
                            </div>
                        @endif

                        <label class="label">Name</label>
                        <input wire:model="fName" class="input" style="margin-bottom:14px">

                        <div class="grid-2" style="margin-bottom:14px">
                            <div>
                                <label class="label">Phone</label>
                                <input wire:model="fPhone" class="input tnum">
                            </div>
                            <div>
                                <label class="label">Email</label>
                                <input type="email" wire:model="fEmail" class="input">
                            </div>
                        </div>

                        <label class="label">Interested in</label>
                        <input wire:model="fCourse" class="input" placeholder="Course or topic" style="margin-bottom:14px">

                        <label class="label">Notes</label>
                        <textarea wire:model="fNotes" class="input" rows="3"></textarea>
                    </div>

                    <div class="drawer-foot">
                        <button class="btn btn-ghost" @click="open=false">Cancel</button>
                        <button class="btn btn-accent" style="margin-left:auto" wire:click="save">Add lead</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
