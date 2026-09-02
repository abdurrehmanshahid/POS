<?php

use App\Models\Cohort;
use App\Models\Course;
use App\Services\Cohorts;
use App\Support\Format;
use Livewire\Volt\Component;

/**
 * Course batches: the intakes students are enrolled into.
 *
 * The screen is organised by course rather than as one flat list, because the
 * question an administrator actually has is "which batch of Shopify is taking
 * students right now?", and that is only answerable per course.
 */
new class extends Component {
    public string $q = '';

    /** Show batches that are no longer taking students. */
    public bool $showClosed = true;

    // ---- Add / edit form ----------------------------------------------------
    public bool $formOpen = false;

    public ?int $editingId = null;

    public ?int $fCourseId = null;

    public string $fName = '';

    public string $fStarts = '';

    public string $fEnds = '';

    public string $fCapacity = '';

    public bool $fOpen = true;

    public string $formError = '';

    private function service(): Cohorts
    {
        return app(Cohorts::class);
    }

    public function newCohort(): void
    {
        abort_unless(auth()->user()->can('cohorts.manage'), 403);
        $this->reset('editingId', 'fName', 'fStarts', 'fEnds', 'fCapacity', 'formError');
        $this->fOpen = true;
        $this->fCourseId = Course::where('is_active', true)->orderBy('code')->value('id');
        $this->formOpen = true;
    }

    public function editCohort(int $id): void
    {
        abort_unless(auth()->user()->can('cohorts.manage'), 403);
        $c = Cohort::findOrFail($id);

        $this->editingId = $c->id;
        $this->fCourseId = $c->course_id;
        $this->fName = $c->name;
        $this->fStarts = $c->starts_on?->toDateString() ?? '';
        $this->fEnds = $c->ends_on?->toDateString() ?? '';
        $this->fCapacity = (string) ($c->capacity ?? '');
        $this->fOpen = $c->is_open;
        $this->formError = '';
        $this->formOpen = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('cohorts.manage'), 403);
        $this->formError = '';

        $payload = [
            'name' => trim($this->fName),
            'course_id' => (int) $this->fCourseId,
            'starts_on' => $this->fStarts ?: null,
            'ends_on' => $this->fEnds ?: null,
            'capacity' => $this->fCapacity === '' ? null : max(1, (int) $this->fCapacity),
            'is_open' => $this->fOpen,
        ];

        try {
            if ($this->editingId) {
                $cohort = Cohort::findOrFail($this->editingId);
                // The course is not editable: moving a batch between courses
                // would drag its students onto a syllabus they never enrolled in.
                $cohort->update([
                    'name' => $payload['name'],
                    'starts_on' => $payload['starts_on'],
                    'ends_on' => $payload['ends_on'],
                    'capacity' => $payload['capacity'],
                ]);
                $this->fOpen ? $this->service()->open($cohort, auth()->user())
                    : $this->service()->close($cohort, auth()->user());
                $msg = $cohort->name.' updated.';
            } else {
                $cohort = $this->service()->create(auth()->user(), $payload);
                $adopted = $cohort->admissions()->count();
                $msg = $cohort->name.' created'
                    .($adopted ? ', '.$adopted.' existing enrolment'.($adopted === 1 ? '' : 's').' joined it.' : '.');
            }
        } catch (\RuntimeException $e) {
            $this->formError = $e->getMessage();

            return;
        }

        $this->formOpen = false;
        $this->dispatch('bbt-toast', tone: 'ok', title: $this->editingId ? 'Batch updated' : 'Batch created', msg: $msg);
    }

    /** Make this the intake for its course, adopting unbatched students. */
    public function openBatch(int $id): void
    {
        abort_unless(auth()->user()->can('cohorts.manage'), 403);
        $cohort = Cohort::findOrFail($id);
        $adopted = $this->service()->open($cohort, auth()->user());

        $this->dispatch('bbt-toast', tone: 'ok', title: $cohort->name.' is now taking students',
            msg: $adopted
                ? $adopted.' existing enrolment'.($adopted === 1 ? '' : 's').' on '.$cohort->course->code.' joined it'
                : 'New enrolments on '.$cohort->course->code.' will join it');
    }

    public function closeBatch(int $id): void
    {
        abort_unless(auth()->user()->can('cohorts.manage'), 403);
        $cohort = Cohort::findOrFail($id);
        $this->service()->close($cohort, auth()->user());

        $this->dispatch('bbt-toast', tone: 'warn', title: $cohort->name.' closed',
            msg: 'New enrolments on '.$cohort->course->code.' will have no batch until another is opened');
    }

    public function with(): array
    {
        $term = trim($this->q);

        $cohorts = Cohort::with(['course', 'admissions.student'])
            ->when(! $this->showClosed, fn ($q) => $q->where('is_open', true))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$term.'%')
                ->orWhereHas('course', fn ($c) => $c
                    ->where('title', 'like', '%'.$term.'%')
                    ->orWhere('code', 'like', '%'.$term.'%'))))
            ->orderByDesc('is_open')
            ->orderByDesc('starts_on')
            ->get();

        return [
            'byCourse' => $cohorts->groupBy(fn (Cohort $c) => $c->course?->code ?? '—'),
            'total' => $cohorts->count(),
            'courses' => Course::orderBy('code')->get(),
        ];
    }
}; ?>

<div class="container-app anim-fade">
    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px">
        <div>
            <h1 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);margin:0;letter-spacing:-.01em">Course batches</h1>
            <div class="tnum" style="font-size:var(--fs-sm);color:var(--muted);margin-top:3px">{{ $total }} total</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div class="search" style="width:300px;max-width:100%">
                <x-icon name="search" :size="15" />
                <input wire:model.live.debounce.200ms="q" placeholder="Search batch or course…" class="input">
            </div>
            <label style="display:flex;align-items:center;gap:7px;font-size:var(--fs-xs);color:var(--ink2);cursor:pointer;white-space:nowrap">
                <input type="checkbox" wire:model.live="showClosed" style="width:15px;height:15px;accent-color:var(--iris)">
                Show closed
            </label>
            <x-ui.busy target="q,showClosed" label="Searching…" />
            <button class="btn btn-accent" wire:click="newCohort"><x-icon name="plus" :size="16" />New batch</button>
        </div>
    </div>

    <div style="display:flex;gap:11px;padding:13px 16px;background:var(--info-bg);border:1px solid var(--border);border-radius:13px;margin-bottom:20px">
        <x-icon name="alert-circle" :size="16" style="color:var(--info);flex:none;margin-top:2px" />
        <div style="font-size:var(--fs-xs);color:var(--ink2);line-height:1.6">
            One batch per course is <strong>open</strong> at a time. New enrolments on that course join it automatically,
            and opening a batch pulls in every existing student on the course who has no batch yet.
        </div>
    </div>

    <div wire:loading.class="is-busy" wire:target="q,showClosed">
    @forelse ($byCourse as $code => $group)
        <div style="margin-bottom:24px">
            <div style="display:flex;align-items:baseline;gap:9px;margin-bottom:11px">
                <span class="tnum" style="font-size:var(--fs-xs);font-weight:800;color:var(--iris);letter-spacing:.03em">{{ $code }}</span>
                <span style="font-size:var(--fs-base);font-weight:700;color:var(--ink)">{{ $group->first()->course?->title }}</span>
                <span class="tnum" style="font-size:var(--fs-xs);color:var(--muted)">{{ $group->count() }} batch{{ $group->count() === 1 ? '' : 'es' }}</span>
            </div>

            <div class="grid-3">
                @foreach ($group as $c)
                    @php $used = $c->seatsUsed(); $left = $c->seatsLeft(); @endphp
                    <div class="card" style="padding:16px 18px;display:flex;flex-direction:column;gap:12px">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
                            <div style="min-width:0">
                                <div style="font-size:var(--fs-md);font-weight:700;color:var(--ink)">{{ $c->name }}</div>
                                <div class="tnum" style="font-size:var(--fs-2xs);color:var(--muted);margin-top:2px">
                                    @if ($c->starts_on)
                                        {{ Format::date($c->starts_on) }}{{ $c->ends_on ? ' → '.Format::date($c->ends_on) : '' }}
                                    @else
                                        No dates set
                                    @endif
                                </div>
                            </div>
                            @if ($c->is_open)
                                <x-ui.pill tone="validated" :dot="true">Taking students</x-ui.pill>
                            @else
                                <x-ui.pill tone="cancelled">Closed</x-ui.pill>
                            @endif
                        </div>

                        <div style="display:flex;align-items:center;justify-content:space-between;font-size:var(--fs-xs)">
                            <span class="tnum" style="color:var(--ink2);font-weight:600">
                                {{ $used }} student{{ $used === 1 ? '' : 's' }}
                            </span>
                            <span class="tnum" style="color:{{ $c->isFull() ? 'var(--over)' : 'var(--muted)' }};font-weight:600">
                                {{ $c->capacity === null ? 'No cap' : ($c->isFull() ? 'Full' : $left.' seats left') }}
                            </span>
                        </div>

                        <div class="card-foot">
                            @if ($c->is_open)
                                <button class="btn btn-sm btn-ghost" wire:click="closeBatch({{ $c->id }})">Close intake</button>
                            @else
                                <button class="btn btn-sm btn-ghost" wire:click="openBatch({{ $c->id }})">Open intake</button>
                            @endif
                            <div class="card-foot-actions">
                                <button class="btn btn-sm btn-primary" wire:click="editCohort({{ $c->id }})">
                                    <x-icon name="edit" :size="14" /> Edit
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="panel"><div class="empty-state" style="padding:40px">
            No batches yet. Create one to group a course's students into an intake.
        </div></div>
    @endforelse
    </div>

    {{-- ---- Add / edit drawer ---------------------------------------------- --}}
    <div x-data="{ open: @entangle('formOpen') }">
        <template x-if="open">
            <div>
                <div class="drawer-backdrop" @click="open=false"></div>
                <div class="drawer">
                    <div class="drawer-head">
                        <div class="drawer-head-icon"><x-icon name="users" :size="19" /></div>
                        <div class="drawer-head-text">
                            <div class="drawer-head-title">{{ $editingId ? 'Edit batch' : 'New batch' }}</div>
                            <div class="drawer-head-sub">An intake of one course, the "Batch #" printed on fee challans.</div>
                        </div>
                        <button class="btn-icon" @click="open=false"><x-icon name="x" :size="18" /></button>
                    </div>

                    <div class="drawer-body">
                        @if ($formError)
                            <div style="padding:11px 14px;background:var(--over-bg);border:1px solid var(--over-br);border-radius:11px;font-size:var(--fs-xs);color:var(--over);font-weight:600;margin-bottom:16px">
                                {{ $formError }}
                            </div>
                        @endif

                        <label class="label">Course</label>
                        <select wire:model="fCourseId" class="select" style="margin-bottom:14px" @disabled($editingId)>
                            @foreach ($courses as $course)
                                <option value="{{ $course->id }}">{{ $course->code }} · {{ $course->title }}</option>
                            @endforeach
                        </select>
                        @if ($editingId)
                            <div style="font-size:var(--fs-2xs);color:var(--faint);margin:-10px 0 14px">
                                A batch cannot change course: its students enrolled on this one.
                            </div>
                        @endif

                        <label class="label">Batch name</label>
                        <input wire:model="fName" class="input" placeholder="Batch # 11" style="margin-bottom:14px">

                        <div class="grid-2" style="margin-bottom:14px">
                            <div>
                                <label class="label">Starts on</label>
                                <input type="date" wire:model="fStarts" class="input">
                            </div>
                            <div>
                                <label class="label">Ends on</label>
                                <input type="date" wire:model="fEnds" class="input">
                            </div>
                        </div>

                        <label class="label">Capacity</label>
                        <input type="number" min="1" wire:model="fCapacity" class="input tnum" placeholder="Leave blank for no cap" style="margin-bottom:14px">

                        <label style="display:flex;align-items:center;gap:9px;font-size:var(--fs-sm);color:var(--ink2);cursor:pointer">
                            <input type="checkbox" wire:model="fOpen" style="width:16px;height:16px;accent-color:var(--iris)">
                            Take new enrolments into this batch
                        </label>
                        <div style="font-size:var(--fs-2xs);color:var(--faint);margin-top:6px;line-height:1.55">
                            Opening this batch closes whichever one is currently open for the course, and adopts every
                            student on the course who has no batch yet.
                        </div>
                    </div>

                    <div class="drawer-foot">
                        <button class="btn btn-ghost" @click="open=false">Cancel</button>
                        <button class="btn btn-accent" style="margin-left:auto" wire:click="save">
                            {{ $editingId ? 'Save changes' : 'Create batch' }}
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
