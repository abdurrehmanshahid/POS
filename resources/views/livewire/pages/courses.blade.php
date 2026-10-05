<?php

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Teacher;
use App\Services\Audit;
use App\Support\Format;
use App\Support\RevenueShare;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Volt\Component;

new class extends Component {
    // Drawer open state (entangled with Alpine).
    public bool $detailOpen = false;
    public bool $formOpen = false;

    // Selected course for the detail drawer.
    public ?int $selectedId = null;

    // Form drawer fields.
    public ?int $editingId = null;
    public string $code = '';
    public string $title = '';
    /**
     * The instructor's name, typed.
     *
     * This was a <select> over the `teachers` table, and it could only ever say
     * "None": nothing in the application creates a teacher. There is no
     * Teachers screen, no route, and the table's only writer is DemoDataSeeder,
     * which production is forbidden to run. So on the institute's box the
     * dropdown listed zero options and the field was unfillable.
     *
     * A typed name, but still stored as a relation. `courses.trainer_id` is
     * read in seven places — the fee voucher, the challan drawer, the course
     * list and detail, and the registration wizard, where a course can be found
     * by typing its instructor's name. Flattening it to a string column would
     * mean a migration and edits to all of them for an identical typing
     * experience, and would lose that search.
     *
     * So the input is free text and `resolveTrainer()` maps it onto a row:
     * an existing instructor if the name matches one, a new one if not.
     */
    public string $trainerName = '';
    public $fee = null;
    public $capacity = null;
    public string $fStatus = 'active';

    /**
     * The course's modules as the form holds them: a list of
     * ['id' => ?int, 'title' => string, 'fee' => mixed].
     *
     * `id` is null for a row the officer has just added and present for one
     * already in the catalogue, which is what lets save() tell "rename this
     * module" apart from "retire that one and sell a new one" — a distinction
     * that matters because the first must not disturb the enrolments already
     * pointing at it.
     *
     * Empty means the course is priced whole by `fee`, which is every course
     * in the catalogue today.
     */
    public array $modules = [];

    /** Which list is showing: active | inactive | archived. */
    public string $tab = 'active';

    // ---- Detail drawer -----------------------------------------------------

    public function viewCourse(int $id): void
    {
        $this->selectedId = $id;
        $this->detailOpen = true;
    }

    // ---- Toggle active -----------------------------------------------------

    /**
     * Set the course's active state to `$to`, rather than flipping it.
     *
     * A flip is not idempotent, and the button is one click away from proving
     * it: two clicks landing before the first response returns flip twice and
     * land back where they started, while both toasts cheerfully report the
     * change. The officer walks away believing a course is live when it is not,
     * and the registration wizard quietly refuses to offer it.
     *
     * The caller sends the state it wants, so a repeat writes the same value
     * and no token is needed. This is the same reason the payment guard exists,
     * fixed the cheaper way because the operation genuinely can be idempotent.
     */
    public function setActive(int $id, bool $to): void
    {
        if (! auth()->user()->can('courses.manage')) {
            return;
        }
        $course = Course::find($id);
        if (! $course) {
            return;
        }
        $course->update(['is_active' => $to]);
        $this->dispatch('bbt-toast', tone: 'info',
            title: $to ? 'Course activated' : 'Course deactivated',
            msg: $course->code);
    }

    // ---- Archive -----------------------------------------------------------

    /**
     * Archive or restore a course. Like setActive(), the caller states the
     * result it wants, so a double-click writes the same value twice.
     *
     * Archiving also deactivates, so the wizard, attendance and batches screens
     * stop offering the course without each learning about archiving. Restoring
     * brings it back as inactive: putting it on sale again is a separate,
     * deliberate step.
     */
    public function setArchived(int $id, bool $to): void
    {
        if (! auth()->user()->can('courses.manage')) {
            return;
        }
        $course = Course::find($id);
        if (! $course || $course->isArchived() === $to) {
            return;
        }
        $course->update($to
            ? ['archived_at' => now(), 'is_active' => false]
            : ['archived_at' => null]);

        Audit::record($to ? 'Course archived' : 'Course restored from archive', auth()->user(), [
            'subject' => $course,
            'subject_label' => $course->code.' · '.$course->title,
        ]);

        $this->detailOpen = false;
        $this->dispatch('bbt-toast', tone: 'info',
            title: $to ? 'Course archived' : 'Course restored',
            msg: $to ? $course->code.' moved to Archived.' : $course->code.' is back under Inactive.');
    }

    // ---- Form drawer -------------------------------------------------------

    public function addCourse(): void
    {
        if (! auth()->user()->can('courses.manage')) {
            return;
        }
        $this->resetForm();
        $this->formOpen = true;
    }

    public function editCourse(int $id): void
    {
        if (! auth()->user()->can('courses.manage')) {
            return;
        }
        $course = Course::find($id);
        if (! $course) {
            return;
        }
        $this->editingId = $course->id;
        $this->code = $course->code;
        $this->title = $course->title;
        $this->trainerName = $course->trainer?->name ?? '';
        $this->fee = $course->fee;
        $this->capacity = $course->capacity;
        $this->fStatus = $course->is_active ? 'active' : 'inactive';
        // Retired modules are deliberately not loaded. They exist only to keep
        // old vouchers readable; putting them back in front of the officer
        // would invite them to be re-activated by accident.
        $this->modules = $course->modules()->sellable()->get()
            ->map(fn (CourseModule $m) => ['id' => $m->id, 'title' => $m->title, 'fee' => $m->fee])
            ->values()->all();
        $this->resetErrorBag();
        $this->detailOpen = false;
        $this->formOpen = true;
    }

    public function save(): void
    {
        if (! auth()->user()->can('courses.manage')) {
            return;
        }

        // Normalise inputs.
        $this->code = trim((string) $this->code);
        $this->title = trim((string) $this->title);
        $this->fee = ($this->fee === '' || $this->fee === null) ? null : (int) $this->fee;
        $this->capacity = ($this->capacity === '' || $this->capacity === null) ? null : (int) $this->capacity;
        // Collapse runs of whitespace as well as trimming, so "Umer  Ali" and
        // "Umer Ali" are the same instructor rather than two.
        $this->trainerName = preg_replace('/\s+/u', ' ', trim($this->trainerName));

        $validator = Validator::make([
            'code' => $this->code,
            'title' => $this->title,
            'fee' => $this->fee,
            'capacity' => $this->capacity,
            'trainerName' => $this->trainerName,
            'fStatus' => $this->fStatus,
        ], [
            'code' => 'required|string|max:40',
            'title' => 'required|string|max:120',
            'fee' => 'required|integer|min:1',
            'capacity' => 'nullable|integer|min:1',
            'trainerName' => 'nullable|string|max:120',
            'fStatus' => 'required|in:active,inactive',
        ], [
            'code.required' => 'Code is required.',
            'title.required' => 'Title is required.',
            'fee.required' => 'Fee is required.',
            'fee.integer' => 'Fee must be a whole number.',
            'fee.min' => 'Fee must be greater than 0.',
            'capacity.integer' => 'Capacity must be a whole number.',
            'capacity.min' => 'Capacity must be at least 1.',
            'trainerName.max' => 'Instructor name is too long (120 characters maximum).',
        ]);

        // Modules, if any. Each needs a name and a real price: a module at
        // zero is not a free module, it is an unfinished form, and saving it
        // would put a line on a fee voucher that explains none of the total.
        $validator->after(function ($v) {
            foreach ($this->modules as $i => $m) {
                if (trim((string) ($m['title'] ?? '')) === '') {
                    $v->errors()->add('modules.'.$i.'.title', 'Module '.($i + 1).' needs a name.');
                }
                if ((int) ($m['fee'] ?? 0) < 1) {
                    $v->errors()->add('modules.'.$i.'.fee', 'Module '.($i + 1).' needs a fee above 0.');
                }
            }
        });

        // Case-insensitive uniqueness on code (exclude self on edit).
        $validator->after(function ($v) {
            $dupe = Course::whereRaw('LOWER(code)=?', [strtolower($this->code)])
                ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
                ->exists();
            if ($this->code !== '' && $dupe) {
                $v->errors()->add('code', 'A course with this code already exists.');
            }
        });

        if ($validator->fails()) {
            $this->resetErrorBag();
            foreach ($validator->errors()->messages() as $field => $msgs) {
                foreach ($msgs as $m) {
                    $this->addError($field, $m);
                }
            }
            $this->dispatch('bbt-toast', tone: 'err', title: 'Check the form', msg: 'Please fix the highlighted fields.');

            return;
        }

        $course = $this->editingId ? Course::find($this->editingId) : new Course();
        $course->fill([
            'code' => $this->code,
            'title' => $this->title,
            'trainer_id' => $this->resolveTrainer(),
            'fee' => $this->fee,
            'capacity' => $this->capacity,
            'is_active' => $this->fStatus === 'active',
        ]);
        // Making an archived course active is taking it out of the archive.
        if ($course->is_active) {
            $course->archived_at = null;
        }
        $course->save();

        $this->syncModules($course);

        $this->dispatch('bbt-toast', tone: 'ok', title: 'Course saved', msg: $course->code);
        $this->formOpen = false;
        $this->resetForm();
    }

    /**
     * Write the form's module rows onto the course.
     *
     * Three cases, and the third is the one worth spelling out:
     *
     *   kept    matched by id, updated in place. An enrolment that bought
     *           "Module 2" keeps pointing at the same row through a rename or
     *           a re-price, so its voucher still names what it bought.
     *   added   a row with no id. Created at the next free position.
     *   gone    in the catalogue but no longer on the form. NEVER hard-deleted
     *           if it has been sold: `admission_modules` restricts on delete,
     *           so the database would refuse anyway, and the right answer is
     *           not to force it through but to stop offering the module while
     *           leaving every voucher that lists it intact.
     */
    protected function syncModules(Course $course): void
    {
        $keptIds = collect($this->modules)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        foreach ($course->modules()->sellable()->get() as $existing) {
            if (in_array($existing->id, $keptIds, true)) {
                continue;
            }

            if ($existing->hasBeenSold()) {
                $existing->update(['is_active' => false]);
            } else {
                $existing->delete();
            }
        }

        foreach (array_values($this->modules) as $i => $row) {
            $attributes = [
                // Position is the form's order, so moving a module up the
                // list is the same action as renumbering it. Safe to assign
                // straight through because (course_id, seq) is a plain index
                // rather than a unique one — see 2026_09_17_000002 for why it
                // has to be, and why no two-pass shuffle is needed here.
                'seq' => $i + 1,
                'title' => trim((string) $row['title']),
                'fee' => (int) $row['fee'],
                'is_active' => true,
            ];

            if ($row['id']) {
                CourseModule::where('id', $row['id'])->update($attributes);
            } else {
                $course->modules()->create($attributes);
            }
        }
    }

    /**
     * The typed name as an instructor row, creating one if this is a new name.
     *
     * Matched case-insensitively, because the officer who types "umer ali" on
     * Tuesday and "Umer Ali" on Friday means the same person, and two rows
     * would split that person's courses in the wizard's search and print two
     * spellings on two vouchers.
     *
     * `withTrashed()` so a name that was removed is restored rather than
     * duplicated. `Teacher` soft-deletes, and a second row with the same name
     * beside a hidden first one is the kind of thing nobody finds until the
     * numbers stop adding up.
     */
    protected function resolveTrainer(): ?int
    {
        if ($this->trainerName === '') {
            return null;
        }

        $teacher = Teacher::withTrashed()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($this->trainerName)])
            ->first();

        if ($teacher) {
            if ($teacher->trashed()) {
                $teacher->restore();
            }

            return $teacher->id;
        }

        // `created_at` explicitly: Teacher has $timestamps = false, so nothing
        // fills it for us and the column is not nullable in spirit.
        return Teacher::create([
            'name' => $this->trainerName,
            'created_at' => now(),
        ])->id;
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->code = '';
        $this->title = '';
        $this->trainerName = '';
        $this->fee = null;
        $this->capacity = null;
        $this->fStatus = 'active';
        $this->modules = [];
        $this->resetErrorBag();
    }

    // ---- Modules -----------------------------------------------------------

    public function addModule(): void
    {
        if (! auth()->user()->can('courses.manage')) { return; }
        $this->modules[] = ['id' => null, 'title' => 'Module '.(count($this->modules) + 1), 'fee' => null];
    }

    /**
     * Take a module off the form.
     *
     * What actually happens to it is settled in save(), not here: one that has
     * never been sold is deleted outright, and one with enrolments behind it
     * is retired instead. Deciding that at this point would mean writing to
     * the catalogue before the officer has pressed Save.
     */
    public function removeModule(int $i): void
    {
        if (! auth()->user()->can('courses.manage')) { return; }
        unset($this->modules[$i]);
        $this->modules = array_values($this->modules);
    }

    /** What the full course will cost once these modules are saved. */
    public function moduleTotal(): int
    {
        return (int) collect($this->modules)->sum(fn ($m) => (int) ($m['fee'] ?? 0));
    }

    public function with(): array
    {
        $all = Course::with(['trainer', 'admissions', 'modules'])->orderBy('code')->get();
        $selected = $this->selectedId ? $all->firstWhere('id', $this->selectedId) : null;

        $groups = [
            'active' => $all->filter(fn (Course $c) => ! $c->isArchived() && $c->is_active)->values(),
            'inactive' => $all->filter(fn (Course $c) => ! $c->isArchived() && ! $c->is_active)->values(),
            'archived' => $all->filter(fn (Course $c) => $c->isArchived())->values(),
        ];
        $courses = $groups[$this->tab] ?? $groups['active'];

        $enrolled = collect();
        $revenue = 0;
        if ($selected) {
            $enrolled = Admission::with(['student', 'challan.installments', 'challan.payments'])
                ->where('course_id', $selected->id)
                ->where('status', 'validated')
                ->get();

            // Σ this course's share of the payments actually banked.
            //
            // Two defects in the previous version. It summed whole invoices
            // reached through their anchor, so a course billed on a grouped
            // invoice it did not head earned nothing while a course that did
            // head one earned the other courses' money too. And it read the
            // paid flag, so a student halfway through paying counted as zero,
            // which is the same divergence between this screen and the
            // dashboard that the payments ledger was introduced to end.
            $revenue = (int) (DB::table('payments')
                ->join('challans', 'challans.id', '=', 'payments.challan_id')
                ->join('admissions', 'admissions.challan_id', '=', 'challans.id')
                ->where('admissions.course_id', $selected->id)
                ->where('admissions.status', '!=', 'cancelled')
                ->selectRaw(RevenueShare::sumOfPayments().' as total')
                ->value('total') ?? 0);
        }

        return [
            'courses' => $courses,
            'tabCounts' => array_map(fn ($g) => $g->count(), $groups),
            'selected' => $selected,
            'enrolled' => $enrolled,
            'revenue' => $revenue,
            'teachers' => Teacher::orderBy('name')->get(),
            'canManage' => auth()->user()->can('courses.manage'),
        ];
    }
}; ?>

<div class="container-app anim-fade">
    {{-- Header row --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <div style="display:inline-flex;background:var(--surface3);border-radius:11px;padding:4px">
                @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $key => $label)
                    <button wire:click="$set('tab','{{ $key }}')" class="btn btn-sm {{ $tab === $key ? 'btn-primary' : '' }}"
                            style="{{ $tab === $key ? '' : 'background:transparent;color:var(--ink2)' }}">
                        {{ $label }} <span class="tnum" style="opacity:.75">{{ $tabCounts[$key] }}</span>
                    </button>
                @endforeach
            </div>
            <div style="font-size:var(--fs-sm);color:var(--muted);font-weight:500">Click a card to view roster</div>
        </div>
        @if ($canManage)
            <button class="btn btn-accent" wire:click="addCourse">
                <x-icon name="plus" :size="17" /> Add Course
            </button>
        @endif
    </div>

    {{-- Course grid --}}
    @if ($courses->isEmpty())
        <div class="panel"><div class="empty-state">
            @if ($tab === 'archived')
                No archived courses. Archive a course you no longer run to move it here.
            @elseif ($tab === 'inactive')
                No inactive courses.
            @else
                No active courses.
            @endif
        </div></div>
    @else
        <div class="grid-3">
            @foreach ($courses as $c)
                @php
                    $used = $c->seatsUsed();
                    $cap = $c->capacity;
                    $left = $c->seatsLeft();
                    $full = $c->isFull();
                    $pct = $cap !== null ? min(100, round($used / max($cap, 1) * 100)) : 0;
                    $barColor = $full ? 'var(--over)' : ($pct >= 85 ? 'var(--due)' : 'var(--navy2)');
                @endphp
                <div class="card" style="padding:18px;cursor:pointer;display:flex;flex-direction:column;gap:12px"
                     wire:click="viewCourse({{ $c->id }})">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
                        <div style="min-width:0">
                            <div class="tnum" style="font-size:var(--fs-xs);font-weight:800;letter-spacing:.03em;color:var(--iris)">{{ $c->code }}</div>
                            <div style="font-size:var(--fs-md);font-weight:700;color:var(--ink);margin-top:3px;line-height:1.3">{{ $c->title }}</div>
                        </div>
                        @if ($c->isArchived())
                            <x-ui.pill tone="navy">Archived</x-ui.pill>
                        @elseif ($c->is_active)
                            <x-ui.pill tone="validated">Active</x-ui.pill>
                        @else
                            <x-ui.pill tone="cancelled">Inactive</x-ui.pill>
                        @endif
                    </div>

                    <div style="display:flex;align-items:center;gap:7px;font-size:var(--fs-xs);color:var(--muted)">
                        <x-icon name="users" :size="15" style="color:var(--faint)" />
                        {{ $c->trainer?->name ?? 'No instructor assigned' }}
                    </div>

                    {{-- Capacity --}}
                    @if ($cap === null)
                        <div style="display:flex;align-items:center;justify-content:space-between;font-size:var(--fs-xs);font-weight:600">
                            <span style="color:var(--muted)">No cap</span>
                            <span style="color:var(--paid)">Open</span>
                        </div>
                    @else
                        <div>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:var(--fs-xs);font-weight:600;margin-bottom:6px">
                                <span class="tnum" style="color:var(--ink2)">{{ $full ? 'Full' : $used.'/'.$cap.' · '.$left.' left' }}</span>
                                <span class="tnum" style="color:var(--faint)">{{ $pct }}%</span>
                            </div>
                            <div class="bar"><span style="width:{{ $pct }}%;background:{{ $barColor }}"></span></div>
                        </div>
                    @endif

                    {{-- The fee is the number people scan for, so it never wraps
                         and never shares a line with a word. Deactivate is an
                         icon: spelling it out cost ~90px and pushed the amount
                         onto two lines on anything narrower than a desktop. --}}
                    <div class="card-foot">
                        <div>
                            <div style="font-size:var(--fs-3xs);font-weight:700;letter-spacing:.04em;color:var(--faint)">FEE</div>
                            <div class="tnum" style="font-size:var(--fs-md);font-weight:800;color:var(--ink);white-space:nowrap">{{ Format::money($c->fee) }}</div>
                        </div>
                        @if ($canManage)
                            @php $toggleLabel = $c->is_active ? 'Deactivate course' : 'Activate course'; @endphp
                            <div class="card-foot-actions">
                                @if ($c->isArchived())
                                <button class="btn btn-sm btn-ghost" wire:click.stop="setArchived({{ $c->id }}, false)"
                                        wire:loading.attr="disabled" wire:target="setArchived({{ $c->id }}, false)">
                                    <x-icon name="restore" :size="14" /> Restore
                                </button>
                                @else
                                <button class="btn-icon btn-icon-plain"
                                        wire:click.stop="setArchived({{ $c->id }}, true)"
                                        wire:confirm="Archive {{ $c->code }}? It is hidden from the course lists and can no longer be registered for. You can restore it from the Archived tab."
                                        wire:loading.attr="disabled" wire:target="setArchived({{ $c->id }}, true)"
                                        title="Archive course" aria-label="Archive course">
                                    <x-icon name="archive" :size="17" />
                                </button>
                                {{-- The state to move TO, not "flip". Two clicks
                                     on a flip cancel out; two clicks on this
                                     write the same value twice. --}}
                                <button class="btn-icon btn-icon-plain"
                                        wire:click.stop="setActive({{ $c->id }}, {{ $c->is_active ? 'false' : 'true' }})"
                                        {{-- Targeted at this course's call, not the bare method name:
                                             `wire:target="setActive"` matches every card and would grey
                                             out the whole catalogue for one toggle's round trip. --}}
                                        wire:loading.attr="disabled"
                                        wire:target="setActive({{ $c->id }}, {{ $c->is_active ? 'false' : 'true' }})"
                                        title="{{ $toggleLabel }}" aria-label="{{ $toggleLabel }}">
                                    <x-icon :name="$c->is_active ? 'minus-circle' : 'check-circle'" :size="17" />
                                </button>
                                <button class="btn btn-sm btn-primary" wire:click.stop="editCourse({{ $c->id }})">
                                    <x-icon name="edit" :size="14" /> Edit
                                </button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Detail drawer --}}
    @if ($selected)
        <div x-data="{ open: @entangle('detailOpen') }">
            <template x-if="open">
                <div>
                    <div class="drawer-backdrop" @click="open=false"></div>
                    <div class="drawer">
                        <div class="drawer-head">
                            <div style="flex:1;min-width:0">
                                <div class="tnum" style="font-size:var(--fs-xs);font-weight:800;letter-spacing:.03em;color:var(--iris)">{{ $selected->code }}</div>
                                <div style="display:flex;align-items:center;gap:10px;margin-top:4px">
                                    <div style="font-size:var(--fs-lg);font-weight:800;color:var(--ink)">{{ $selected->title }}</div>
                                    @if ($selected->isArchived())
                                        <x-ui.pill tone="navy">Archived</x-ui.pill>
                                    @elseif ($selected->is_active)
                                        <x-ui.pill tone="validated">Active</x-ui.pill>
                                    @else
                                        <x-ui.pill tone="cancelled">Inactive</x-ui.pill>
                                    @endif
                                </div>
                            </div>
                            <button class="btn-icon" @click="open=false"><x-icon name="x" :size="18" /></button>
                        </div>

                        <div class="drawer-body">
                            {{-- Summary --}}
                            <div class="grid-2" style="margin-bottom:22px">
                                <div class="card" style="padding:14px 16px">
                                    <div style="font-size:var(--fs-2xs);font-weight:700;letter-spacing:.04em;color:var(--faint)">TRAINER</div>
                                    <div style="font-size:var(--fs-base);font-weight:700;color:var(--ink);margin-top:4px">{{ $selected->trainer?->name ?? 'No instructor assigned' }}</div>
                                </div>
                                <div class="card" style="padding:14px 16px">
                                    <div style="font-size:var(--fs-2xs);font-weight:700;letter-spacing:.04em;color:var(--faint)">FEE</div>
                                    <div class="tnum" style="font-size:var(--fs-base);font-weight:700;color:var(--ink);margin-top:4px">{{ Format::money($selected->fee) }}</div>
                                </div>
                                <div class="card" style="padding:14px 16px">
                                    <div style="font-size:var(--fs-2xs);font-weight:700;letter-spacing:.04em;color:var(--faint)">ENROLLED</div>
                                    <div class="tnum" style="font-size:var(--fs-base);font-weight:700;color:var(--ink);margin-top:4px">{{ $selected->seatsUsed() }}{{ $selected->capacity !== null ? ' / '.$selected->capacity : '' }}</div>
                                </div>
                                <div class="card" style="padding:14px 16px">
                                    <div style="font-size:var(--fs-2xs);font-weight:700;letter-spacing:.04em;color:var(--faint)">CAPACITY</div>
                                    <div class="tnum" style="font-size:var(--fs-base);font-weight:700;color:var(--ink);margin-top:4px">{{ $selected->capacity !== null ? $selected->capacity.' seats' : 'Unlimited' }}</div>
                                </div>
                            </div>

                            <div style="background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:14px;padding:16px 18px;color:#fff;margin-bottom:22px">
                                <div style="font-size:var(--fs-2xs);font-weight:700;letter-spacing:.04em;color:#b9bcdd">REVENUE · PAID CHALLANS</div>
                                <div class="tnum" style="font-size:var(--fs-2xl);font-weight:800;margin-top:5px">{{ Format::money($revenue) }}</div>
                            </div>

                            {{-- Enrolled students --}}
                            <div style="font-size:var(--fs-sm);font-weight:700;color:var(--ink);margin-bottom:12px">Enrolled students</div>
                            @forelse ($enrolled as $a)
                                @php $st = $a->challan?->paymentState(); @endphp
                                <div style="display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--surface3)">
                                    <x-ui.avatar :name="$a->student->name" variant="navy" :size="34" />
                                    <div style="flex:1;min-width:0">
                                        <div style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)">{{ $a->student->name }}</div>
                                        <div class="tnum rec-id" style="font-size:var(--fs-xs);color:var(--muted)">{{ $a->student->student_code }}</div>
                                    </div>
                                    <div class="tnum" style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)">{{ Format::money($a->netShare()) }}</div>
                                    @if ($st)
                                        <x-ui.pill tone="{{ $st }}" :dot="true">{{ ucfirst($st) }}</x-ui.pill>
                                    @endif
                                </div>
                            @empty
                                <div class="empty-state">No students enrolled yet.</div>
                            @endforelse
                        </div>

                        @if ($canManage)
                            <div class="drawer-foot">
                                @if ($selected->isArchived())
                                    <button class="btn btn-ghost" wire:click="setArchived({{ $selected->id }}, false)">
                                        <x-icon name="restore" :size="16" /> Restore from archive
                                    </button>
                                @else
                                    <button class="btn btn-ghost" wire:click="setArchived({{ $selected->id }}, true)"
                                            wire:confirm="Archive {{ $selected->code }}? It is hidden from the course lists and can no longer be registered for. You can restore it from the Archived tab.">
                                        <x-icon name="archive" :size="16" /> Archive
                                    </button>
                                @endif
                                <button class="btn btn-primary" wire:click="editCourse({{ $selected->id }})">
                                    <x-icon name="edit" :size="16" /> Edit course
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            </template>
        </div>
    @endif

    {{-- Form drawer (create / edit) --}}
    @if ($canManage)
        <div x-data="{ open: @entangle('formOpen') }">
            <template x-if="open">
                <div>
                    <div class="drawer-backdrop" @click="open=false"></div>
                    <div class="drawer">
                        <div class="drawer-head">
                            <div class="drawer-head-icon"><x-icon name="courses" :size="19" /></div>
                            <div class="drawer-head-text">
                                <div class="drawer-head-title">{{ $editingId ? 'Edit course' : 'Add course' }}</div>
                                <div class="drawer-head-sub">{{ $editingId ? 'Update the catalog entry.' : 'Create a new catalog entry.' }}</div>
                            </div>
                            <button class="btn-icon btn-icon-plain" @click="open=false" title="Close"><x-icon name="x" :size="17" /></button>
                        </div>

                        <div class="drawer-body">
                            <div style="margin-bottom:16px">
                                <label class="label">Code *</label>
                                <input type="text" class="input @error('code') is-error @enderror" wire:model="code" placeholder="e.g. WD-101">
                                @error('code') <span class="field-error">{{ $message }}</span> @enderror
                            </div>

                            <div style="margin-bottom:16px">
                                <label class="label">Title *</label>
                                <input type="text" class="input @error('title') is-error @enderror" wire:model="title" placeholder="Course title">
                                @error('title') <span class="field-error">{{ $message }}</span> @enderror
                            </div>

                            <div style="margin-bottom:16px">
                                <label class="label">Instructor</label>
                                {{-- Typed, not chosen. The <select> this replaces listed the
                                     `teachers` table, and nothing in the application ever puts a
                                     row in it — no screen, no route, and its only writer is the
                                     demo seeder that production must not run. On the institute's
                                     box it offered exactly one option, "None".

                                     `list` gives the browser's own suggestions from the names
                                     already used, so the common case is one keystroke and a
                                     click, while a name nobody has used yet still just types.
                                     A datalist SUGGESTS and never constrains — unlike a select,
                                     an unrecognised value is simply kept. --}}
                                <input type="text" class="input @error('trainerName') is-error @enderror"
                                       wire:model="trainerName" list="bbt-instructors"
                                       autocomplete="off" placeholder="e.g. Umer Ali — leave blank if not yet assigned">
                                <datalist id="bbt-instructors">
                                    @foreach ($teachers as $t)
                                        <option value="{{ $t->name }}"></option>
                                    @endforeach
                                </datalist>
                                @error('trainerName') <span class="field-error">{{ $message }}</span> @enderror
                                <p style="font-size:var(--fs-2xs);color:var(--muted);margin:5px 0 0">
                                    A name not used before is added to the list. Matching is not
                                    case-sensitive, so "umer ali" is the same instructor as "Umer Ali".
                                </p>
                            </div>

                            <div class="grid-2" style="margin-bottom:16px">
                                <div>
                                    <label class="label">Fee (PKR) *</label>
                                    <input type="number" min="1" class="input @error('fee') is-error @enderror" wire:model="fee" placeholder="0">
                                    @error('fee') <span class="field-error">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="label">Capacity</label>
                                    <input type="number" min="1" class="input @error('capacity') is-error @enderror" wire:model="capacity" placeholder="Unlimited">
                                    @error('capacity') <span class="field-error">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            {{-- Modules. Optional, and most courses have
                                 none: a course with an empty list is sold
                                 whole at the Fee above, exactly as every
                                 course was before modules existed. --}}
                            <div style="margin-bottom:16px;padding:14px;border:1.5px solid var(--border2);border-radius:12px;background:var(--surface2)">
                                <div style="display:flex;justify-content:space-between;align-items:center">
                                    <label class="label" style="margin:0">Modules <span class="label-opt">Optional</span></label>
                                    <button type="button" class="btn btn-sm btn-ghost" wire:click="addModule">
                                        <x-icon name="plus" :size="14" /> Add module
                                    </button>
                                </div>

                                @if (! $modules)
                                    <p style="font-size:var(--fs-2xs);color:var(--muted);margin:8px 0 0">
                                        This course is sold whole, at the fee above. Add modules to let a
                                        student buy part of it &mdash; the full course then costs whatever
                                        the modules add up to.
                                    </p>
                                @else
                                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px">
                                        @foreach ($modules as $i => $m)
                                            <div style="display:flex;gap:8px;align-items:flex-start">
                                                <span class="tnum" style="flex:none;width:22px;padding-top:9px;font-size:var(--fs-2xs);font-weight:700;color:var(--faint)">{{ $i + 1 }}.</span>
                                                <div style="flex:1">
                                                    <input class="input @error('modules.'.$i.'.title') is-error @enderror"
                                                           wire:model="modules.{{ $i }}.title" placeholder="Module name">
                                                    @error('modules.'.$i.'.title') <span class="field-error">{{ $message }}</span> @enderror
                                                </div>
                                                <div style="flex:none;width:130px">
                                                    <input type="number" min="1" class="input tnum @error('modules.'.$i.'.fee') is-error @enderror"
                                                           wire:model.live.debounce.400ms="modules.{{ $i }}.fee" placeholder="Fee">
                                                    @error('modules.'.$i.'.fee') <span class="field-error">{{ $message }}</span> @enderror
                                                </div>
                                                <button type="button" class="btn btn-sm btn-ghost" style="flex:none;margin-top:1px"
                                                        wire:click="removeModule({{ $i }})" title="Remove this module"
                                                        wire:confirm="Are you sure you want to delete this module? It is removed when you save the course.">
                                                    <x-icon name="x" :size="14" />
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:10px;border-top:1px solid var(--border2)">
                                        <span style="font-size:var(--fs-xs);font-weight:700;color:var(--ink)">Full course</span>
                                        <span class="tnum" style="font-size:var(--fs-sm);font-weight:800;color:var(--navy)">{{ Format::money($this->moduleTotal()) }}</span>
                                    </div>
                                    {{-- Said out loud because the Fee box above
                                         is still on screen and still filled in.
                                         An officer who changes it and sees no
                                         effect on the wizard would reasonably
                                         conclude the save had failed. --}}
                                    <p style="font-size:var(--fs-2xs);color:var(--muted);margin:8px 0 0">
                                        While this course has modules, registrations are priced from them and the
                                        Fee field above is not used.
                                    </p>
                                    {{-- Removing a module the institute has
                                         already sold cannot erase it: the
                                         vouchers that list it have to keep
                                         adding up. --}}
                                    <p style="font-size:var(--fs-2xs);color:var(--muted);margin:4px 0 0">
                                        Removing a module that students have already bought retires it instead of
                                        deleting it. It stops being offered; existing enrolments and their vouchers
                                        are untouched.
                                    </p>
                                @endif
                            </div>

                            <div style="margin-bottom:4px">
                                <label class="label">Status</label>
                                <select class="select" wire:model="fStatus">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="drawer-foot">
                            <button class="btn btn-accent" wire:click="save">
                                <x-icon name="check" :size="16" /> Save course
                            </button>
                            <button class="btn btn-ghost" @click="open=false">Cancel</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>
