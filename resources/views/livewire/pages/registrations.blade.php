<?php

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Counter;
use App\Models\Course;
use App\Models\Student;
use App\Services\ChallanActions;
use App\Services\Operations;
use App\Services\RegistrationService;
use App\Support\Concerns\CollectsPayments;
use App\Support\Concerns\GuardsDoubleSubmit;
use App\Support\Concerns\ReversesPayments;
use App\Support\Contact;
use App\Support\Matcher;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;

new class extends Component {
    use CollectsPayments, GuardsDoubleSubmit, ReversesPayments;

    // List + shared drawer state
    public string $q = '';
    public ?int $drawerId = null;
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

    /**
     * How the fee is meant to be paid — printed on the voucher.
     *
     * Empty is a real answer, not an unset one: an officer who has not agreed a
     * method yet leaves it blank and the voucher prints a dash. Distinct from
     * `paid_via`, which records what actually happened when the money arrived.
     */
    public string $paymentMethod = '';

    public bool $genChallans = true;
    public array $wizErrors = [];

    /** Fields the officer has left or submitted; see touch(). */
    public array $wizTouched = [];

    /** Existing student holding the CNIC currently typed, if any. */
    public ?int $cnicClashId = null;

    // ---- Shared drawer -----------------------------------------------------
    public function select(int $challanId): void { $this->drawerId = $challanId; }
    public function closeDrawer(): void { $this->drawerId = null; }
    public function askCancel(int $admissionId): void { $this->cancelAdmId = $admissionId; $this->cancelReason = ''; $this->cancelError = ''; }

    public function confirmCancel(): void
    {
        $this->cancelError = '';
        if ($this->cancelAdmId === null) { return; }
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

    /**
     * Supplies CollectsPayments.
     *
     * Deliberately NOT `Ledger::scopedChallans()`, which the Challans screen
     * uses: this list is about registrations rather than money, so it shows
     * cancelled ones too. The trait takes the query rather than assuming one
     * for exactly this reason.
     */
    protected function scopedChallans(): Builder
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
        // ?new=1 opens a blank wizard. The dashboard's "New registration" tile
        // is meant to start the job, not to land the officer on a list they then
        // have to find a button on.
        if (request()->boolean('new') && auth()->user()->can('registrations.create')) {
            $this->openWizard();

            return;
        }

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
            'newGuardian', 'newPhone', 'newCnic', 'courseIds', 'discountPct', 'discountReason', 'paymentMethod',
            'wizErrors', 'wizTouched']);
        $this->step = 1;
        $this->mode = 'new';
        $this->genChallans = true;
        $this->wizardOpen = true;
        // A token for this run through the wizard. It survives every step, so
        // the two clicks a double-submit produces on the final screen both
        // carry it. See GuardsDoubleSubmit.
        $this->freshOperationKey('enrol');
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
        // Caught here so the officer learns before the review step, not after
        // pressing Register. The service and a unique index both refuse it too.
        if (in_array($id, $this->enrolledCourseIds(), true)) {
            $this->dispatch('bbt-toast', tone: 'warn', title: 'Already enrolled',
                msg: 'This student is already on '.$course->title.'.');
            return;
        }
        $this->courseIds = in_array($id, $this->courseIds)
            ? array_values(array_diff($this->courseIds, [$id]))
            : [...$this->courseIds, $id];
    }

    public function back(): void { if ($this->step > 1) { $this->step--; } }

    /**
     * Courses the selected student already holds a live enrolment on.
     *
     * Empty for a brand new student, who by definition is on nothing yet.
     *
     * @return list<int>
     */
    public function enrolledCourseIds(): array
    {
        if ($this->mode !== 'existing' || ! $this->pickedStudentId) {
            return [];
        }

        return Admission::where('student_id', $this->pickedStudentId)
            ->where('status', '!=', 'cancelled')
            ->pluck('course_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ---- Live step-1 validation --------------------------------------------
    //
    // The wizard used to say nothing until Continue was pressed, so a mistyped
    // CNIC surfaced only after the officer had mentally moved on, and a person
    // already in the system was not discovered until the unique index rejected
    // the insert at the very end. Each field now answers for itself as it is
    // typed, and the CNIC additionally looks for the human behind it.

    public function updatedNewName(): void { $this->checkName($this->isTouched('name')); }

    public function updatedNewPhone(): void { $this->checkPhone($this->isTouched('phone')); }

    public function updatedNewCnic(): void { $this->checkCnic(); }

    /** Guardian is optional free text, so there is nothing to be wrong about. */
    public function updatedNewGuardian(): void {}

    /**
     * Mark a field finished and re-run its check. Bound to blur in the view.
     *
     * Emptiness and wrongness are different failures and want different timing.
     * "Wrong" can be said the moment it is knowable. "Empty" cannot: every
     * field starts empty, so an emptiness error fired on keystroke accuses the
     * officer of not having finished typing — type one letter, delete it, and
     * the form calls it an error. That is precisely how people are trained to
     * stop reading red text. So emptiness waits until the officer has left the
     * field or pressed Continue; wrongness does not wait.
     */
    public function touch(string $field): void
    {
        $this->wizTouched[$field] = true;

        match ($field) {
            'name' => $this->checkName(true),
            'phone' => $this->checkPhone(true),
            'cnic' => $this->checkCnic(),
            default => null,
        };
    }

    private function isTouched(string $field): bool
    {
        return (bool) ($this->wizTouched[$field] ?? false);
    }

    private function checkName(bool $touched): void
    {
        // Clears the instant it becomes valid, whether or not it was touched.
        if (trim($this->newName) !== '') {
            $this->setFieldError('name', null);

            return;
        }

        $this->setFieldError('name', $touched ? 'Student name is required.' : null);
    }

    /**
     * Required, because students.phone is NOT NULL and it is the only channel a
     * fee reminder actually travels down. Guardian and CNIC are not: the schema
     * made both nullable in 2026_08_08_000001 so an imported roll could carry
     * "name, course, batch, phone and money, nothing else", and a counter that
     * demands more than the database does is inventing a rule of its own.
     */
    private function checkPhone(bool $touched): void
    {
        $raw = trim($this->newPhone);

        if ($raw === '') {
            $this->setFieldError('phone', $touched ? 'A phone number is required.' : null);

            return;
        }

        if (Contact::normalizePhone($raw)) {
            $this->setFieldError('phone', null);

            return;
        }

        // Silent mid-entry: a half-typed number is not yet a wrong one. Once
        // it is long enough to be a complete attempt, it is fair to say so.
        //
        // Counted in DIGITS, not characters. A PK mobile carries ten significant
        // digits and reaches that at 10, 11 or 12 characters typed depending on
        // whether it is written 3001234567, 03001234567 or +923001234567 — and
        // formatted with spaces, "+92 300 1234" is twelve characters with only
        // eight of the ten digits in it. A character threshold therefore fired
        // the error while the officer still had three digits left to type,
        // which is the exact nagging the rest of this method exists to avoid.
        $digits = strlen((string) preg_replace('/\D+/', '', $raw));

        $this->setFieldError('phone', $touched || $digits >= 10
            ? 'Enter a valid PK mobile (+92 3XX XXXXXXX).'
            : null);
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
        $this->wizTouched = [];
        $this->reset('newName', 'newGuardian', 'newPhone', 'newCnic');

        $this->dispatch('bbt-toast', tone: 'ok', title: 'Existing student selected', msg: $student->name.' · '.$student->student_code);
    }

    /**
     * Guardians already on file whose name begins with what is being typed.
     *
     * Siblings are far and away the most common reason two students share a
     * guardian — the institute already grants a "Sibling discount" — so
     * re-typing a parent's name and number for the second child is work the
     * system can simply do. Each suggestion carries the phone last recorded
     * against that guardian, so accepting one fills both fields.
     *
     * Scoped through visibleTo, unlike the CNIC lookup which is deliberately
     * global. That one exists to stop a duplicate person being created and is
     * worth the reach; this one is a convenience, and convenience is not a good
     * enough reason to let an officer harvest the guardian names and phone
     * numbers of students they are not allowed to see.
     *
     * @return list<array{name:string, phone:string}>
     */
    public function guardianMatches(): array
    {
        $term = trim($this->newGuardian);

        // Step 1 only. The guardian field is not on steps 2 and 3, but the
        // property keeps its value, so without this the query ran again on every
        // render of the course picker and the review screen to build a list
        // nothing displays.
        if ($this->step !== 1 || $this->mode !== 'new' || mb_strlen($term) < 2) {
            return [];
        }

        // A guardian typing "50%" should search for that, not match everything.
        $like = addcslashes($term, '%_\\').'%';

        return Student::visibleTo(auth()->user())
            ->whereNotNull('guardian_name')
            ->where('guardian_name', 'like', $like)
            ->orderByDesc('id')
            // Bounded in SQL, because two characters against a full institute
            // roll otherwise hydrates every match to show five. Not limited to
            // 5: the de-duplication below is per distinct guardian and happens
            // in PHP, so five rows can collapse to one sibling's parent and the
            // query has to leave the list something to work with.
            ->limit(50)
            ->get(['guardian_name', 'phone'])
            ->unique(fn ($s) => mb_strtolower($s->guardian_name))
            // Nothing to suggest once the name is fully typed, whether by hand
            // or by accepting a suggestion; otherwise the list hangs around
            // offering the officer the exact words already in the box.
            ->reject(fn ($s) => mb_strtolower(trim($s->guardian_name)) === mb_strtolower($term))
            ->take(5)
            ->map(fn ($s) => ['name' => $s->guardian_name, 'phone' => (string) $s->phone])
            ->values()
            ->all();
    }

    public function useGuardian(string $name, string $phone): void
    {
        $this->newGuardian = $name;

        // Only fills an empty phone. Overwriting a number the officer has
        // already typed would be the suggestion overruling the person, and the
        // second child's contact number is not always the first one's.
        if (trim($this->newPhone) === '' && $phone !== '') {
            $this->newPhone = $phone;
            $this->checkPhone($this->isTouched('phone'));
        }
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
                // Pressing Continue is itself a statement that the officer is
                // finished, so every field counts as touched from here on.
                $this->wizTouched = ['name' => true, 'phone' => true, 'cnic' => true];
                $this->checkName(true);
                $this->checkPhone(true);
                $this->checkCnic();

                // CNIC is optional, but a half-typed one is not "omitted", it is
                // wrong. Without this a partial number would pass here (checkCnic
                // stays quiet below 15 chars so it does not nag mid-entry) and be
                // stored as though it were a real identity number.
                if (trim($this->newCnic) !== '' && ! Contact::validCnic($this->newCnic)) {
                    $this->wizErrors['cnic'] = 'CNIC must be #####-#######-#, or leave it blank.';
                }

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
            // Checked here as well as in the service, because the value arrives
            // from the client: the buttons offer only the configured list, and
            // a crafted request could put anything in this column and print it
            // on a voucher as though the institute had agreed to it.
            if ($this->paymentMethod !== '' && ! in_array($this->paymentMethod, config('institute.payment_methods'), true)) {
                $this->wizErrors['method'] = 'That is not a payment method the institute accepts.';

                return;
            }
        }
        if ($this->step < 3) { $this->step++; }
    }

    public function submit(): void
    {
        if (! auth()->user()->can('registrations.create')) { abort(403); }
        // The wizard has already been submitted and closed, so there is nothing
        // left to register. This is what the second half of a double-click looks
        // like, and the token alone does NOT catch it: Livewire bundles two
        // clicks fired in one tick into a single request as two calls, and the
        // first call's success path re-mints the token in memory (below), so the
        // second call arrives holding a *fresh* key and passes `once()` cleanly.
        // Verified in a browser: a double-click on Register produced two
        // students, two admissions and two challans, with two operations rows
        // carrying different keys. `wire:loading.attr="disabled"` cannot help
        // either, because both clicks land before the first request is issued.
        //
        // `confirmPay()` has survived this all along by returning early on
        // `payId === null`; this is the same guard the enrolment path was
        // missing. See CollectsPayments::confirmPay().
        if (! $this->wizardOpen) { return; }
        $data = [
            'course_ids' => $this->courseIds,
            'discount_pct' => $this->discountPct,
            'discount_reason' => $this->discountReason,
            'payment_method' => $this->paymentMethod,
            // Actually honoured now. The review step has always shown this
            // checkbox; until it was passed through, unticking it still raised
            // a challan and the label was simply untrue.
            'generate_challans' => $this->genChallans,
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
            // Guarded because the new-student path has no natural key to fall
            // back on. An existing student is caught by the unique live
            // enrolment index (BUG-17), but a brand new one is a brand new row
            // every time: submitted twice it produced two people, two
            // admissions and two challans, billing the family double.
            $op = app(Operations::class)->once($this->operationKey('enrol'), 'registration.create',
                fn () => app(RegistrationService::class)->register(auth()->user(), $data));
        } catch (\Throwable $e) {
            $this->dispatch('bbt-toast', tone: 'err', title: 'Registration failed', msg: $e->getMessage());
            return;
        }
        $this->wizardOpen = false;
        $this->freshOperationKey('enrol');

        if ($op->replayed) {
            $this->dispatch('bbt-toast', tone: 'ok', title: 'Student enrolled',
                msg: 'Already recorded', note: 'Duplicate submission ignored');

            return;
        }

        $n = count($op->value['admissions']);
        $this->dispatch('bbt-toast', tone: 'ok', title: 'Student enrolled', msg: $n.' admission'.($n === 1 ? '' : 's').' created');
    }

    public function with(): array
    {
        $user = auth()->user();
        $matcher = new Matcher($this->q);
        $canAll = $user->can('scope.all');

        $admissions = Admission::visibleTo($user)
            ->with(['student', 'course', 'enroller', 'challan.installments'])
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
                'enrolledCourseIds' => $this->enrolledCourseIds(),
                'activeCourses' => $activeCourses,
                'selectedCourses' => $selectedCourses,
                'base' => $base, 'disc' => $disc, 'net' => $base - $disc,
                'studentCodePreview' => $seq::studentCode($this->newType, $nextStud),
                'admPreview' => $count === 1
                    ? $seq::admissionNo($nextAdm)
                    : $seq::admissionNo($nextAdm).' to '.$seq::admissionNo($nextAdm + $count - 1),
                // Scoped, like every other student list in the system. Unscoped,
                // this box let an officer search the whole institute by name or
                // CNIC and enrol anybody in it, which contradicted both
                // useExistingStudent() and mount() a few lines up.
                'matches' => $this->mode === 'existing' && strlen(trim($this->studentSearch)) >= 1
                    ? Student::visibleTo($user)
                        ->where(fn ($w) => $w->where('name', 'like', '%'.$this->studentSearch.'%')
                            ->orWhere('cnic', 'like', '%'.$this->studentSearch.'%')
                            ->orWhere('student_code', 'like', '%'.$this->studentSearch.'%'))
                        ->limit(6)->get()
                    : collect(),
                'cnicClash' => $this->cnicClashId ? Student::find($this->cnicClashId) : null,
                'guardianMatches' => $this->guardianMatches(),
                'pickedStudent' => $this->pickedStudentId ? Student::visibleTo($user)->find($this->pickedStudentId) : null,
            ];
        }

        return array_merge([
            'canCreate' => $user->can('registrations.create'),
            'canPay' => $user->can('challans.pay'),
            'canCancel' => $user->can('registrations.create'),
            // Supervisor only, and identical to the Challans screen — both
            // include the same drawer partial, so both must supply it.
            'canReverse' => $user->can('payments.reverse'),
            'scopeLabel' => $canAll ? 'All registrations' : 'My registrations',
            'rows' => $admissions,
            'selected' => $this->drawerId
                // `payments.reversals` eager loaded for the same reason as on
                // the Challans screen: the ledger block shows each handover net
                // of corrections, and netAmount() answers from the loaded
                // relation rather than one query per payment.
                ? $this->scopedChallans()->with(['admission.student', 'admission.course.trainer', 'admission.cohort', 'admission.enroller', 'discountApprover', 'auditLogs.actor', 'installments', 'payments.receiver', 'payments.reversals.approver'])->find($this->drawerId)
                : null,
            'payChallan' => $this->payId ? $this->scopedChallans()->with(['admission.student', 'payments.reversals'])->find($this->payId) : null,
            'reversePayment' => $this->reverseId
                ? \App\Models\Payment::with(['reversals', 'challan.student'])
                    ->whereIn('challan_id', $this->scopedChallans()->select('challans.id'))
                    ->find($this->reverseId)
                : null,
        ], $wizard);
    }
}; ?>

@php use App\Support\Format; @endphp

<div class="container-app anim-fade">
    @if (! $wizardOpen)
        {{-- ===================== LIST ===================== --}}
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px">
            <div style="flex:1"><span style="font-size:var(--fs-md);font-weight:700;color:var(--ink)">{{ $scopeLabel }}</span> <span style="font-size:var(--fs-xs);color:var(--muted)">{{ $rows->count() }} records</span></div>
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
                                    <div><div style="font-size:var(--fs-sm);font-weight:600;color:var(--ink)">{{ $a->student->name }}</div><div class="tnum rec-id" style="font-size:var(--fs-2xs);font-weight:700;color:var(--iris)">{{ $a->student->student_code }}</div></div>
                                </div>
                            </td>
                            <td>{{ $a->course->title }}</td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <x-ui.avatar :name="$a->enroller->name" variant="navy" :size="24" />
                                    <span style="font-size:var(--fs-xs);color:var(--ink2)">{{ $a->enroller->name }}</span>
                                </div>
                            </td>
                            <td class="right tnum" style="font-weight:700">{{ Format::money($a->netShare()) }}</td>
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
