<?php

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Teacher;
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
    public $trainerId = null;
    public $fee = null;
    public $capacity = null;
    public string $fStatus = 'active';

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
        $this->trainerId = $course->trainer_id;
        $this->fee = $course->fee;
        $this->capacity = $course->capacity;
        $this->fStatus = $course->is_active ? 'active' : 'inactive';
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
        $this->trainerId = ($this->trainerId === '' || $this->trainerId === null) ? null : (int) $this->trainerId;

        $validator = Validator::make([
            'code' => $this->code,
            'title' => $this->title,
            'fee' => $this->fee,
            'capacity' => $this->capacity,
            'trainerId' => $this->trainerId,
            'fStatus' => $this->fStatus,
        ], [
            'code' => 'required|string|max:40',
            'title' => 'required|string|max:120',
            'fee' => 'required|integer|min:1',
            'capacity' => 'nullable|integer|min:1',
            'trainerId' => 'nullable|integer|exists:teachers,id',
            'fStatus' => 'required|in:active,inactive',
        ], [
            'code.required' => 'Code is required.',
            'title.required' => 'Title is required.',
            'fee.required' => 'Fee is required.',
            'fee.integer' => 'Fee must be a whole number.',
            'fee.min' => 'Fee must be greater than 0.',
            'capacity.integer' => 'Capacity must be a whole number.',
            'capacity.min' => 'Capacity must be at least 1.',
            'trainerId.exists' => 'Select a valid trainer.',
        ]);

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
            'trainer_id' => $this->trainerId,
            'fee' => $this->fee,
            'capacity' => $this->capacity,
            'is_active' => $this->fStatus === 'active',
        ])->save();

        $this->dispatch('bbt-toast', tone: 'ok', title: 'Course saved', msg: $course->code);
        $this->formOpen = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->code = '';
        $this->title = '';
        $this->trainerId = null;
        $this->fee = null;
        $this->capacity = null;
        $this->fStatus = 'active';
        $this->resetErrorBag();
    }

    public function with(): array
    {
        $courses = Course::with(['trainer', 'admissions'])->orderBy('code')->get();
        $selected = $this->selectedId ? $courses->firstWhere('id', $this->selectedId) : null;

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
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px">
        <div style="font-size:var(--fs-base);color:var(--muted);font-weight:500">
            <span class="tnum" style="color:var(--ink);font-weight:700">{{ $courses->count() }}</span>
            courses · click a card to view roster
        </div>
        @if ($canManage)
            <button class="btn btn-accent" wire:click="addCourse">
                <x-icon name="plus" :size="17" /> Add Course
            </button>
        @endif
    </div>

    {{-- Course grid --}}
    @if ($courses->isEmpty())
        <div class="panel"><div class="empty-state">No courses in the catalog yet.</div></div>
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
                        @if ($c->is_active)
                            <x-ui.pill tone="validated">Active</x-ui.pill>
                        @else
                            <x-ui.pill tone="cancelled">Inactive</x-ui.pill>
                        @endif
                    </div>

                    <div style="display:flex;align-items:center;gap:7px;font-size:var(--fs-xs);color:var(--muted)">
                        <x-icon name="users" :size="15" style="color:var(--faint)" />
                        {{ $c->trainer?->name ?? 'No trainer assigned' }}
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
                                    @if ($selected->is_active)
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
                                    <div style="font-size:var(--fs-base);font-weight:700;color:var(--ink);margin-top:4px">{{ $selected->trainer?->name ?? 'No trainer assigned' }}</div>
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
                                <label class="label">Trainer</label>
                                <select class="select @error('trainerId') is-error @enderror" wire:model="trainerId">
                                    <option value="">None</option>
                                    @foreach ($teachers as $t)
                                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                                    @endforeach
                                </select>
                                @error('trainerId') <span class="field-error">{{ $message }}</span> @enderror
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
