<?php

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Counter;
use App\Models\Course;
use App\Models\Student;
use App\Services\ChallanActions;
use App\Services\RegistrationService;
use App\Support\Contact;
use App\Support\Matcher;
use Livewire\Volt\Component;

new class extends Component {
    // List + shared drawer state
    public string $q = '';
    public ?int $drawerId = null;
    public ?int $payId = null;
    public string $payMethod = '';
    public int $payAmount = 0;
    public ?int $cancelAdmId = null;
    public string $cancelReason = '';
    public string $cancelError = '';

    // Wizard state
    public bool $wizardOpen = false;
    public int $step = 1;
    public string $mode = 'new';           // existing | new
    public string $studentSearch = '';
    public ?int $pickedStudentId = null;
    public string $newType = 'R';
    public string $newName = '';
    public string $newGuardian = '';
    public string $newPhone = '';
    public string $newCnic = '';
    public array $courseIds = [];
    public int $discountPct = 0;
    public string $discountReason = '';
    public bool $genChallans = true;
    public array $wizErrors = [];

    /** Existing student holding the CNIC currently typed, if any. */
    public ?int $cnicClashId = null;

    // ---- Shared drawer -----------------------------------------------------
    public function select(int $challanId): void { $this->drawerId = $challanId; }
    public function closeDrawer(): void { $this->drawerId = null; }
    public function askPay(int $id): void
    {
        $this->payId = $id;
        $this->payMethod = '';
        $this->payAmount = (int) ($this->scopedChallans()->find($id)?->balance() ?? 0);
    }

    public function confirmPay(): void
    {
        $challan = $this->scopedChallans()->find($this->payId);
        if (! $challan || ! auth()->user()->can('challans.pay')) { abort(403); }
        try {
            app(ChallanActions::class)->recordPayment($challan, auth()->user(), $this->payAmount, $this->payMethod);
        } catch (\Throwable $e) {
            $this->dispatch('bbt-toast', tone: 'err', title: 'Could not record payment', msg: $e->getMessage());
            return;
        }
        $this->payId = null;
        $this->dispatch('bbt-toast',
            tone: 'ok',
            title: $challan->fresh()->isPaid() ? 'Payment recorded' : 'Part payment received',
            // Fully qualified: the template below already imports Format, and
            // Volt compiles both blocks into one file.
            msg: $challan->challan_no.' · '.\App\Support\Format::money($this->payAmount).' · '.$this->payMethod,
        );
    }

    public function askCancel(int $admissionId): void { $this->cancelAdmId = $admissionId; $this->cancelReason = ''; $this->cancelError = ''; }

    public function confirmCancel(): void
    {
        $this->cancelError = '';
        if (! auth()->user()->can('registrations.create')) { abort(403); }
        $admission = Admission::visibleTo(auth()->user())->find($this->cancelAdmId);
        if (! $admission) { abort(404); }
        try {
            app(ChallanActions::class)->cancel($admission, auth()->user(), $this->cancelReason);
        } catch (\Throwable $e) { $this->cancelError = $e->getMessage(); return; }
        $this->cancelAdmId = null;
        $this->drawerId = null;
        $this->dispatch('bbt-toast', tone: 'warn', title: 'Registration cancelled', msg: 'Soft-deleted and recoverable');
    }

    private function scopedChallans()
    {
        return Challan::query()->whereHas('admission', fn ($a) => $a->visibleTo(auth()->user()));
    }

    /**
     * Deep link from the student drawer: /registrations?enrol=<id>.
     *
     * Opens the wizard already past step 1 with that student selected, which is
     * the whole point, enrolling an EXISTING person must not walk back through
     * the "add new student" form, because that is how duplicate records get
     * created. The student is resolved through the visibility scope, so an
     * officer cannot deep-link their way to somebody else's student.
     */
    public function mount(): void
    {
        $id = (int) request()->query('enrol');
        if ($id <= 0 || ! auth()->user()->can('registrations.create')) {
            return;
        }

        $student = Student::visibleTo(auth()->user())->find($id);
        if (! $student) {
            return;
        }

        $this->openWizard();
        $this->mode = 'existing';
        $this->pickedStudentId = $student->id;
        $this->step = 2;
    }

    // ---- Wizard ------------------------------------------------------------
    public function openWizard(): void
    {
        $this->reset(['step', 'mode', 'studentSearch', 'pickedStudentId', 'newType', 'newName',
            'newGuardian', 'newPhone', 'newCnic', 'courseIds', 'discountPct', 'discountReason', 'wizErrors']);
        $this->step = 1;
        $this->mode = 'new';
        $this->genChallans = true;
        $this->wizardOpen = true;
    }

    public function closeWizard(): void { $this->wizardOpen = false; }

    public function pickStudent(int $id): void { $this->pickedStudentId = $id; }

    public function toggleCourse(int $id): void
    {
        $course = Course::find($id);
        if (! $course || $course->isFull()) {
            $this->dispatch('bbt-toast', tone: 'warn', title: 'Course is full', msg: $course?->title);
            return;
        }
        $this->courseIds = in_array($id, $this->courseIds)
            ? array_values(array_diff($this->courseIds, [$id]))
            : [...$this->courseIds, $id];
    }

    public function back(): void { if ($this->step > 1) { $this->step--; } }

    // ---- Live step-1 validation --------------------------------------------
    //
    // The wizard used to say nothing until Continue was pressed, so a mistyped
    // CNIC surfaced only after the officer had mentally moved on, and a person
    // already in the system was not discovered until the unique index rejected
    // the insert at the very end. Each field now answers for itself as it is
    // typed, and the CNIC additionally looks for the human behind it.

    public function updatedNewName(): void { $this->checkName(); }

    public function updatedNewGuardian(): void { $this->checkGuardian(); }

    public function updatedNewPhone(): void { $this->checkPhone(); }

    public function updatedNewCnic(): void { $this->checkCnic(); }

    private function checkName(): void
    {
        $this->setFieldError('name', trim($this->newName) === '' ? 'Student name is required.' : null);
    }

    private function checkGuardian(): void
    {
        $this->setFieldError('guardian', trim($this->newGuardian) === '' ? 'Guardian name is required.' : null);
    }

    private function checkPhone(): void
    {
        $raw = trim($this->newPhone);
        // Silent while the number is still being typed. Nagging someone about an
        // incomplete value they are mid-way through entering trains them to
        // ignore the error colour entirely.
        $this->setFieldError('phone', $raw === '' || Contact::normalizePhone($raw)
            ? null
            : 'Enter a valid PK mobile (+92 3XX XXXXXXX).');
    }

    /**
     * Format first, then identity. A CNIC uniquely identifies a person, so one
     * already on file almost always means "this student is back for another
     * course", not "a new person coincidentally shares an ID". The clash is
     * therefore surfaced as an offer to enrol them, not as a dead end.
     */
    private function checkCnic(): void
    {
        $this->cnicClashId = null;
        $cnic = trim($this->newCnic);

        if ($cnic === '' || strlen($cnic) < 15) {
            $this->setFieldError('cnic', null);

            return;
        }

        if (! Contact::validCnic($cnic)) {
            $this->setFieldError('cnic', 'CNIC must be #####-#######-#.');

            return;
        }

        $clash = Student::withTrashed()->where('cnic', $cnic)->first();

        if ($clash && $clash->trashed()) {
            $this->setFieldError('cnic', 'That CNIC belongs to a removed student ('.$clash->student_code.'). Restore that record instead.');

            return;
        }

        $this->setFieldError('cnic', null);
        // A live student is shown as a card with an action, not as an error.
        $this->cnicClashId = $clash?->id;
    }

    private function setFieldError(string $key, ?string $message): void
    {
        if ($message === null) {
            unset($this->wizErrors[$key]);
        } else {
            $this->wizErrors[$key] = $message;
        }
    }

    /**
     * Switch to the existing person instead of minting a duplicate of them.
     * Scoped through visibleTo so this cannot be used to reach a student the
     * officer is not allowed to see, even though the CNIC lookup that surfaced
     * them is deliberately global.
     */
    public function useExistingStudent(): void
    {
        $student = $this->cnicClashId
            ? Student::visibleTo(auth()->user())->find($this->cnicClashId)
            : null;

        if (! $student) {
            $this->dispatch('bbt-toast', tone: 'warn', title: 'That record is not yours to enrol',
                msg: 'Ask an administrator to enrol this student.');

            return;
        }

        $this->mode = 'existing';
        $this->pickedStudentId = $student->id;
        $this->studentSearch = $student->student_code;
        $this->cnicClashId = null;
        $this->wizErrors = [];
        $this->reset('newName', 'newGuardian', 'newPhone', 'newCnic');

        $this->dispatch('bbt-toast', tone: 'ok', title: 'Existing student selected', msg: $student->name.' · '.$student->student_code);
    }

    public function next(): void
    {
        $this->wizErrors = [];
        if ($this->step === 1) {
            if ($this->mode === 'existing') {
                if (! $this->pickedStudentId) { $this->wizErrors['student'] = 'Select a student to continue.'; return; }
            } else {
                // Re-run every check rather than trusting what the live hooks
                // left behind: a field never touched has never been validated.
                $this->checkName();
                $this->checkGuardian();
                if (! Contact::normalizePhone($this->newPhone)) { $this->wizErrors['phone'] = 'Enter a valid PK mobile (+92 3XX XXXXXXX).'; }
                if (! Contact::validCnic($this->newCnic)) { $this->wizErrors['cnic'] = 'CNIC must be #####-#######-#.'; }
                else { $this->checkCnic(); }

                if ($this->cnicClashId) {
                    $this->dispatch('bbt-toast', tone: 'warn', title: 'That CNIC is already registered',
                        msg: 'Enrol the existing student instead of creating a duplicate.');

                    return;
                }
                if ($this->wizErrors) { $this->dispatch('bbt-toast', tone: 'err', title: 'Fix the highlighted fields'); return; }
            }
        }
        if ($this->step === 2) {
            if (! $this->courseIds) { $this->wizErrors['courses'] = 'Select at least one course.'; $this->dispatch('bbt-toast', tone: 'err', title: 'Select at least one course'); return; }
            if ($this->discountPct < 0 || $this->discountPct > 100) { $this->wizErrors['discount'] = 'Discount must be between 0 and 100%.'; return; }
            if ($this->discountPct > 0 && ! trim($this->discountReason)) { $this->wizErrors['discount'] = 'A discount requires a reason (recorded in the audit trail).'; return; }
        }
        if ($this->step < 3) { $this->step++; }
    }

    public function submit(): void
    {
        if (! auth()->user()->can('registrations.create')) { abort(403); }
        $data = [
            'course_ids' => $this->courseIds,
            'discount_pct' => $this->discountPct,
            'discount_reason' => $this->discountReason,
        ];
        if ($this->mode === 'existing') {
            $data['student_id'] = $this->pickedStudentId;
        } else {
            $data['new_student'] = [
                'type' => $this->newType, 'name' => trim($this->newName), 'guardian_name' => trim($this->newGuardian),
                'phone' => Contact::normalizePhone($this->newPhone), 'cnic' => $this->newCnic,
            ];
        }
        try {
            $result = app(RegistrationService::class)->register(auth()->user(), $data);
        } catch (\Throwable $e) {
            $this->dispatch('bbt-toast', tone: 'err', title: 'Registration failed', msg: $e->getMessage());
            return;
        }
        $n = count($result['admissions']);
        $this->wizardOpen = false;
        $this->dispatch('bbt-toast', tone: 'ok', title: 'Student enrolled', msg: $n.' admission'.($n === 1 ? '' : 's').' created');
    }

    public function with(): array
    {
        $user = auth()->user();
        $matcher = new Matcher($this->q);
        $canAll = $user->can('scope.all');

        $admissions = Admission::visibleTo($user)
            ->with(['student', 'course', 'enroller', 'challan'])
            ->latest()
            ->get()
            ->filter(function (Admission $a) use ($matcher) {
                $state = $a->status === 'cancelled' ? 'cancelled' : ($a->challan?->paymentState() ?? 'unpaid');

                return $matcher->matches([
                    '_all' => "{$a->reg_no} {$a->student->name} {$a->student->student_code} {$a->course->title} {$state} {$a->enroller->name}",
                    'id' => $a->reg_no, 'name' => $a->student->name, 'sid' => $a->student->student_code,
                    'course' => $a->course->title, 'status' => $state, 'by' => $a->enroller->name,
                ]);
            })->values();

        $wizard = [];
        if ($this->wizardOpen) {
            $activeCourses = Course::where('is_active', true)->with('admissions')->orderBy('code')->get();
            $selectedCourses = $activeCourses->whereIn('id', $this->courseIds);
            $base = (int) $selectedCourses->sum('fee');
            $disc = $this->discountPct > 0 ? (int) round($base * $this->discountPct / 100) : 0;
            $nextAdm = (int) (Counter::where('key', 'admission')->value('value') ?? 12);
            $nextStud = (int) (Counter::where('key', 'student:'.$this->newType)->value('value') ?? 1);
            $count = max(1, $selectedCourses->count());
            // Formatted by Sequences, not by a second copy of the sprintf: a
            // preview that disagrees with what gets assigned is worse than none.
            $seq = \App\Services\Sequences::class;

            $wizard = [
                'activeCourses' => $activeCourses,
                'selectedCourses' => $selectedCourses,
                'base' => $base, 'disc' => $disc, 'net' => $base - $disc,
                'studentCodePreview' => $seq::studentCode($this->newType, $nextStud),
                'admPreview' => $count === 1
                    ? $seq::admissionNo($nextAdm)
                    : $seq::admissionNo($nextAdm).' to '.$seq::admissionNo($nextAdm + $count - 1),
                'matches' => $this->mode === 'existing' && strlen(trim($this->studentSearch)) >= 1
                    ? Student::where(fn ($w) => $w->where('name', 'like', '%'.$this->studentSearch.'%')
                        ->orWhere('cnic', 'like', '%'.$this->studentSearch.'%')
                        ->orWhere('student_code', 'like', '%'.$this->studentSearch.'%'))
                        ->limit(6)->get()
                    : collect(),
                'cnicClash' => $this->cnicClashId ? Student::find($this->cnicClashId) : null,
                'pickedStudent' => $this->pickedStudentId ? Student::find($this->pickedStudentId) : null,
            ];
        }

        return array_merge([
            'canCreate' => $user->can('registrations.create'),
            'canPay' => $user->can('challans.pay'),
            'canCancel' => $user->can('registrations.create'),
            'scopeLabel' => $canAll ? 'All registrations' : 'My registrations',
            'rows' => $admissions,
            'selected' => $this->drawerId
                ? $this->scopedChallans()->with(['admission.student', 'admission.course.trainer', 'admission.cohort', 'admission.enroller', 'discountApprover', 'auditLogs.actor', 'installments', 'payments.receiver'])->find($this->drawerId)
                : null,
            'payChallan' => $this->payId ? $this->scopedChallans()->with(['admission.student', 'payments'])->find($this->payId) : null,
        ], $wizard);
    }
}; ?>

@php use App\Support\Format; @endphp

<div class="container-app anim-fade">
    @if (! $wizardOpen)
        {{-- ===================== LIST ===================== --}}
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px">
            <div style="flex:1"><span style="font-size:15px;font-weight:700;color:var(--ink)">{{ $scopeLabel }}</span> <span style="font-size:12.5px;color:var(--muted)">{{ $rows->count() }} records</span></div>
            <div class="search" style="width:280px"><x-icon name="search" :size="15" /><input wire:model.live.debounce.200ms="q" class="input" placeholder="Filter · try status:paid or /R26/"></div>
            @if ($canCreate)
                <button class="btn btn-accent" wire:click="openWizard"><x-icon name="plus" :size="17" /> New Registration</button>
            @endif
        </div>

        <div class="panel scroll-x">
            <table class="table">
                <thead><tr>
                    <th>Adm #</th><th>Student</th><th>Course</th><th>Enrolled by</th><th class="right">Net fee</th><th>Payment</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $a)
                        @php
                            $cancelled = $a->status === 'cancelled';
                            $state = $cancelled ? 'cancelled' : ($a->challan?->paymentState() ?? 'unpaid');
                            $pillTone = $cancelled ? 'cancelled' : ($state === 'paid' ? 'paid' : ($state === 'overdue' ? 'overdue' : 'unpaid'));
                            $pillLabel = $cancelled ? 'Cancelled' : ucfirst($state);
                        @endphp
                        <tr class="clickable {{ $cancelled ? 'row-cancelled' : '' }}" @if ($a->challan) wire:click="select({{ $a->challan->id }})" @endif wire:key="reg-{{ $a->id }}">
                            <td class="tnum" style="font-weight:700;color:var(--iris)">{{ $a->reg_no }}</td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px">
                                    <x-ui.avatar :name="$a->student->name" :size="30" />
                                    <div><div style="font-size:13.5px;font-weight:600;color:var(--ink)">{{ $a->student->name }}</div><div class="tnum" style="font-size:11px;font-weight:700;color:var(--iris)">{{ $a->student->student_code }}</div></div>
                                </div>
                            </td>
                            <td>{{ $a->course->title }}</td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <x-ui.avatar :name="$a->enroller->name" variant="navy" :size="24" />
                                    <span style="font-size:12.5px;color:var(--ink2)">{{ $a->enroller->name }}</span>
                                </div>
                            </td>
                            <td class="right tnum" style="font-weight:700">{{ Format::money($a->challan?->net_amount ?? 0) }}</td>
                            <td><x-ui.pill :tone="$pillTone" :dot="! $cancelled">{{ $pillLabel }}</x-ui.pill></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-state">No registrations match your filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.challan-drawer')
    @else
        @include('partials.registration-wizard')
    @endif
</div>
