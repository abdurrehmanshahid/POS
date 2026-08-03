<?php

use App\Models\Cohort;
use App\Models\Course;
use App\Services\Attendances;
use App\Support\Clock;
use Livewire\Volt\Component;

/**
 * Taking the register.
 *
 * One course, one day, one screen. The register is deliberately not a wizard and
 * not a modal: the person using it is standing in front of a class with thirty
 * names to get through, so everything is on one page and every mark is one tap.
 *
 * Saving is idempotent (see App\Services\Attendances), so re-opening a day that
 * has already been taken loads the existing marks and saving again corrects them
 * rather than recording the class twice.
 */
new class extends Component {
    public ?int $courseId = null;

    public ?int $cohortId = null;

    public string $date = '';

    /** @var array<int, string> student id => present|absent|leave */
    public array $marks = [];

    public string $error = '';

    /** True once the loaded day already had marks, drives the copy. */
    public bool $alreadyTaken = false;

    private function service(): Attendances
    {
        return app(Attendances::class);
    }

    public function mount(): void
    {
        $this->date = Clock::today()->toDateString();
        $this->courseId = Course::where('is_active', true)->orderBy('code')->value('id');
        $this->loadRegister();
    }

    public function updatedCourseId(): void
    {
        // A batch belongs to one course, so changing course invalidates it.
        $this->cohortId = null;
        $this->loadRegister();
    }

    public function updatedCohortId(): void
    {
        $this->loadRegister();
    }

    public function updatedDate(): void
    {
        $this->loadRegister();
    }

    /**
     * Pull the roster and whatever was already marked for this day.
     *
     * Students with no existing mark default to `present`. Marking the exceptions
     * is the job: in a class of thirty, two are away, and pre-selecting absent
     * would mean twenty-eight taps to record a normal day.
     */
    public function loadRegister(): void
    {
        $this->error = '';
        $this->marks = [];
        $this->alreadyTaken = false;

        $course = $this->course();
        if (! $course) {
            return;
        }

        $existing = $this->service()->marksFor($course, $this->date);
        $this->alreadyTaken = $existing !== [];

        foreach ($this->service()->roster($course, $this->cohort()) as $student) {
            $this->marks[$student->id] = $existing[$student->id] ?? 'present';
        }
    }

    public function mark(int $studentId, string $status): void
    {
        if (isset($this->marks[$studentId]) && in_array($status, Attendances::STATUSES, true)) {
            $this->marks[$studentId] = $status;
        }
    }

    /** Set every student on the roster to one status. */
    public function markAll(string $status): void
    {
        if (! in_array($status, Attendances::STATUSES, true)) {
            return;
        }

        $this->marks = array_map(fn () => $status, $this->marks);
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('attendance.manage'), 403);

        $course = $this->course();
        if (! $course) {
            $this->error = 'Choose a course first.';

            return;
        }

        try {
            $n = $this->service()->record(auth()->user(), $course, $this->cohort(), $this->date, $this->marks);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->dispatch('bbt-toast', tone: 'err', title: 'Register not saved', msg: $e->getMessage());

            return;
        }

        $this->error = '';
        $this->loadRegister();

        $this->dispatch('bbt-toast', tone: 'ok',
            title: $this->alreadyTaken ? 'Register updated' : 'Register saved',
            msg: $n.' student'.($n === 1 ? '' : 's').' marked for '.\App\Support\Format::date($this->date));
    }

    private function course(): ?Course
    {
        return $this->courseId ? Course::find($this->courseId) : null;
    }

    private function cohort(): ?Cohort
    {
        if (! $this->cohortId) {
            return null;
        }

        $cohort = Cohort::find($this->cohortId);

        // Guard against a stale batch id left over from a course switch.
        return $cohort && $cohort->course_id === $this->courseId ? $cohort : null;
    }

    public function with(): array
    {
        $course = $this->course();

        $counts = array_count_values($this->marks);

        return [
            'courses' => Course::where('is_active', true)->orderBy('code')->get(),
            'batches' => $course ? Cohort::where('course_id', $course->id)->orderByDesc('is_open')->orderBy('name')->get() : collect(),
            'roster' => $course ? $this->service()->roster($course, $this->cohort()) : collect(),
            'selectedCourse' => $course,
            'present' => $counts['present'] ?? 0,
            'absent' => $counts['absent'] ?? 0,
            'onLeave' => $counts['leave'] ?? 0,
            'isFuture' => $this->date > Clock::today()->toDateString(),
        ];
    }
}; ?>

@php use App\Support\Format; @endphp

<div class="container-app anim-fade">

    {{-- Controls --}}
    <div class="card" style="padding:16px 18px;margin-bottom:18px">
        <div class="grid-3" style="gap:14px">
            <div>
                <label class="label">Course</label>
                <select class="input" wire:model.live="courseId">
                    @foreach ($courses as $c)
                        <option value="{{ $c->id }}">{{ $c->code }} · {{ $c->title }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Batch <span style="color:var(--faint);font-weight:500">(optional)</span></label>
                <select class="input" wire:model.live="cohortId">
                    <option value="">All students on this course</option>
                    @foreach ($batches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}{{ $b->is_open ? ' · open' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Session date</label>
                <input type="date" class="input tnum" wire:model.live="date"
                       max="{{ \App\Support\Clock::today()->toDateString() }}">
            </div>
        </div>

        @if ($isFuture)
            <div style="margin-top:12px;padding:10px 14px;background:var(--due-bg);border-radius:10px;font-size:12.5px;color:var(--due);font-weight:600">
                That date has not happened yet. A register can only record a class that has already run.
            </div>
        @elseif ($alreadyTaken)
            <div style="margin-top:12px;padding:10px 14px;background:var(--info-bg);border-radius:10px;font-size:12.5px;color:var(--info);font-weight:600">
                This register was already taken. Saving again corrects it and records the change in the activity log.
            </div>
        @endif
    </div>

    {{-- Tally --}}
    @if ($roster->isNotEmpty())
        <div class="grid-3" style="gap:14px;margin-bottom:18px">
            @foreach ([['Present', $present, 'var(--paid)'], ['Absent', $absent, 'var(--over)'], ['On leave', $onLeave, 'var(--due)']] as [$label, $n, $tone])
                <div class="card" style="padding:14px 16px">
                    <div style="font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:.04em">{{ $label }}</div>
                    <div class="tnum" style="font-size:22px;font-weight:800;color:{{ $tone }};margin-top:2px">{{ $n }}</div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Register --}}
    <div class="panel">
        <div class="panel-head" style="display:flex;align-items:center;gap:12px">
            <h3 class="panel-title" style="flex:1">
                Register
                @if ($selectedCourse)
                    <span style="font-weight:500;color:var(--muted)">· {{ $selectedCourse->code }} · {{ Format::date($date) }}</span>
                @endif
            </h3>
            @if ($roster->isNotEmpty() && ! $isFuture)
                <button class="btn btn-ghost btn-sm" wire:click="markAll('present')">All present</button>
                <button class="btn btn-ghost btn-sm" wire:click="markAll('absent')">All absent</button>
            @endif
        </div>

        @if ($roster->isEmpty())
            <div class="empty-state" style="padding:34px">
                @if (! $selectedCourse)
                    Choose a course to take its register.
                @else
                    No live enrolments on {{ $selectedCourse->code }}{{ $cohortId ? ' in this batch' : '' }} yet, so there is nobody to mark.
                @endif
            </div>
        @else
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Student</th>
                            <th class="right">Mark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roster as $s)
                            @php $current = $marks[$s->id] ?? 'present'; @endphp
                            <tr>
                                <td class="tnum" style="color:var(--iris);font-weight:700">{{ $s->student_code }}</td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:11px">
                                        <x-ui.avatar :name="$s->name" variant="orange" :size="30" />
                                        <div style="min-width:0">
                                            <div style="font-weight:600;color:var(--ink)">{{ $s->name }}</div>
                                            <div style="font-size:12px;color:var(--muted)">{{ $s->guardian_name }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="right">
                                    <div style="display:inline-flex;background:var(--surface3);border-radius:10px;padding:3px;gap:2px">
                                        @foreach ([['present', 'Present', 'var(--paid)'], ['absent', 'Absent', 'var(--over)'], ['leave', 'Leave', 'var(--due)']] as [$key, $label, $tone])
                                            <button wire:click="mark({{ $s->id }}, '{{ $key }}')"
                                                    @disabled($isFuture)
                                                    style="padding:5px 12px;border:0;border-radius:8px;font-size:12px;font-weight:700;cursor:{{ $isFuture ? 'not-allowed' : 'pointer' }};
                                                           background:{{ $current === $key ? $tone : 'transparent' }};
                                                           color:{{ $current === $key ? '#fff' : 'var(--ink2)' }}">{{ $label }}</button>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-top:1px solid var(--border)">
                @if ($error)
                    <span class="field-error" style="flex:1">{{ $error }}</span>
                @else
                    <span style="flex:1;font-size:12.5px;color:var(--muted)">
                        {{ $roster->count() }} student{{ $roster->count() === 1 ? '' : 's' }} on this register.
                    </span>
                @endif
                <button class="btn btn-primary" wire:click="save" @disabled($isFuture)>
                    <x-icon name="check" :size="16" /> {{ $alreadyTaken ? 'Update register' : 'Save register' }}
                </button>
            </div>
        @endif
    </div>
</div>
