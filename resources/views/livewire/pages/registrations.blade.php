<?php

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Counter;
use App\Models\Course;
use App\Models\Student;
use App\Services\ChallanActions;
use App\Services\Operations;
use App\Services\RegistrationService;
use App\Support\Clock;
use App\Support\Concerns\CollectsPayments;
use App\Support\Concerns\GuardsDoubleSubmit;
use App\Support\Concerns\ReversesPayments;
use App\Support\Concerns\SchedulesInstallments;
use App\Support\Contact;
use App\Support\Matcher;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;

new class extends Component {
    use CollectsPayments, GuardsDoubleSubmit, ReversesPayments, SchedulesInstallments;

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

    /**
     * Modules taken, per course: [courseId => list<moduleId>].
     *
     * Only ever holds an entry for a course that is actually divided into
     * modules. Selecting such a course seeds it with ALL of them, because the
     * full course is what an officer means by default and un-ticking two is
     * less work than ticking three.
     */
    public array $moduleIds = [];

    /**
     * Batch chosen per course: [courseId => cohortId|''].
     *
     * '' means "no batch", and it is a real answer rather than an unset one -
     * see RegistrationService::resolveCohorts(). Seeded with the open intake
     * when the course is selected, which is what the system used to do
     * silently and now does visibly.
     */
    public array $batchIds = [];

    /**
     * full | split. The fee in one payment, or an advance and a balance.
     *
     * The Installments service has supported this since it was written and
     * nothing ever offered it: `schedule()` was reachable only from the roll
     * importer, so a plan could be imported but never agreed at the counter.
     */
    public string $payPlan = 'full';

    /**
     * How many parts the plan has: 2 or 3. Only read when payPlan is 'split'.
     *
     * `challans.plan` stays 'split' either way — the column answers "is this
     * paid in one go?", and the schedule itself says how many parts there are.
     * So a third instalment needs no migration.
     */
    public int $planCount = 2;

    /**
     * The typed parts, in whole rupees.
     *
     * The LAST part is never typed: it is the fee less the ones above it, so
     * the schedule always sums to the fee exactly, which is the one thing
     * `Installments::schedule()` refuses outright. On a two-part plan only
     * `planAdvance` is typed; on a three-part plan `planSecondAmount` as well.
     */
    public int $planAdvance = 0;
    public int $planSecondAmount = 0;
    public string $planFirstDue = '';
    public string $planSecondDue = '';
    public string $planThirdDue = '';

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
            'moduleIds', 'batchIds', 'payPlan', 'planCount', 'planAdvance', 'planSecondAmount',
            'planFirstDue', 'planSecondDue', 'planThirdDue',
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
        // A course the student already holds is still selectable: a repeat
        // sitting, or a second batch taken alongside the first, is a real
        // registration the institute takes. The card says so, and so does the
        // toast, so the accidental double-submit is still visible - it is
        // warned about rather than refused.
        if (in_array($id, $this->enrolledCourseIds(), true) && ! in_array($id, $this->courseIds)) {
            $this->dispatch('bbt-toast', tone: 'warn', title: 'Already enrolled',
                msg: 'This student is already on '.$course->title.'. Adding a second enrolment.');
        }
        if (in_array($id, $this->courseIds)) {
            $this->courseIds = array_values(array_diff($this->courseIds, [$id]));
            // Dropped with the course. Leaving them behind meant un-ticking a
            // course and re-ticking it silently restored a half-selection the
            // officer could no longer see.
            unset($this->moduleIds[$id], $this->batchIds[$id]);
        } else {
            $this->courseIds = [...$this->courseIds, $id];
            // Full course by default - every module ticked. The officer
            // narrows it down from there.
            $modules = $course->sellableModules();
            if ($modules->isNotEmpty()) { $this->moduleIds[$id] = $modules->pluck('id')->map(fn ($m) => (int) $m)->all(); }
            // The open intake, which is what registration assigned on its own
            // before this dropdown existed. Now it is merely the default.
            $this->batchIds[$id] = (string) ($course->openCohort()?->id ?? '');
        }
        $this->syncPlanToFee();
    }

    /**
     * Take or drop one module of a course already on the registration.
     *
     * Refuses to empty the list: a course with no modules selected is not a
     * cheaper registration, it is a meaningless one, and the service reads an
     * empty array as "the whole course" and bills the full fee. The officer
     * un-ticks the course itself to drop it.
     */
    public function toggleModule(int $courseId, int $moduleId): void
    {
        if (! in_array($courseId, $this->courseIds, true)) { return; }
        $current = $this->moduleIds[$courseId] ?? [];
        if (in_array($moduleId, $current, true)) {
            if (count($current) === 1) {
                $this->dispatch('bbt-toast', tone: 'warn', title: 'Keep at least one module',
                    msg: 'Un-tick the course itself to remove it from this registration.');
                return;
            }
            $this->moduleIds[$courseId] = array_values(array_diff($current, [$moduleId]));
        } else {
            $this->moduleIds[$courseId] = [...$current, $moduleId];
        }
        $this->syncPlanToFee();
    }

    /**
     * Switch this course from "full course" to picking modules.
     *
     * Drops the LAST module rather than clearing the list, because an empty
     * selection is read as the whole course by both this component and
     * RegistrationService — so clearing it would silently mean the opposite of
     * what the button says. Starting from "all but the last" also leaves the
     * officer un-ticking rather than starting from nothing.
     */
    public function chooseModules(int $courseId): void
    {
        $course = Course::find($courseId);
        if (! $course || ! in_array($courseId, $this->courseIds, true)) { return; }

        $modules = $course->sellableModules();
        if ($modules->count() < 2) { return; }

        $current = $this->moduleIds[$courseId] ?? [];
        if (count($current) < $modules->count()) { return; }

        $this->moduleIds[$courseId] = $modules->pluck('id')->map(fn ($m) => (int) $m)
            ->slice(0, $modules->count() - 1)->values()->all();
        $this->syncPlanToFee();
    }

    /** Put every module of this course back on. */
    public function takeWholeCourse(int $courseId): void
    {
        $course = Course::find($courseId);
        if (! $course || ! in_array($courseId, $this->courseIds, true)) { return; }
        $this->moduleIds[$courseId] = $course->sellableModules()->pluck('id')->map(fn ($m) => (int) $m)->all();
        $this->syncPlanToFee();
    }

    // ---- Payment plan ------------------------------------------------------

    public function updatedPayPlan(): void { $this->syncPlanToFee(); }
    public function updatedDiscountPct(): void { $this->syncPlanToFee(); }
    public function updatedPlanAdvance(): void { $this->syncPlanToFee(); }
    public function updatedPlanSecondAmount(): void { $this->syncPlanToFee(); }

    /**
     * Switch between a two- and three-part plan.
     *
     * The amounts are reset rather than carried across, because a split that
     * was sensible over two parts is rarely the one wanted over three, and a
     * stale advance here is a number the officer did not choose.
     */
    public function setPlan(string $plan, int $count = 2): void
    {
        $this->payPlan = $plan;
        $this->planCount = max(2, min($count, \App\Services\Installments::MAX_PARTS));
        $this->planAdvance = 0;
        $this->planSecondAmount = 0;
        $this->syncPlanToFee();
    }

    /**
     * Keep the advance inside the fee as the fee moves.
     *
     * The fee is not fixed while the wizard is open - every course tick,
     * module tick and nudge of the discount slider changes it. An advance
     * typed against the old total is silently wrong against the new one, and
     * the officer would only find out when the service refused the whole
     * registration on submit. So it is clamped here, and seeded to half on the
     * first switch into a plan.
     */
    private function syncPlanToFee(): void
    {
        $net = $this->netFee();
        if ($this->payPlan !== 'split' || $net <= 0) { return; }

        $today = Clock::today();
        if ($this->planFirstDue === '') { $this->planFirstDue = $today->copy()->addDays(7)->toDateString(); }
        if ($this->planSecondDue === '') { $this->planSecondDue = $today->copy()->addDays(37)->toDateString(); }
        if ($this->planThirdDue === '') { $this->planThirdDue = $today->copy()->addDays(67)->toDateString(); }

        // Seeded from the service, so the wizard's suggestion and the plan the
        // service would have built on its own are the same numbers rather than
        // two implementations of "split it evenly".
        $certificate = $this->certificateFee();
        $tuition = $net - $certificate;
        $later = intdiv($tuition, $this->planCount);

        if ($this->planAdvance <= 0) { $this->planAdvance = $net - $later * ($this->planCount - 1); }
        if ($this->planCount > 2 && $this->planSecondAmount <= 0) { $this->planSecondAmount = $later; }

        // Floor on the advance is the certificate charge, so editing it down
        // cannot push the charge into a later part. Every part after the first
        // needs at least a rupee left for it, which is what bounds the typed
        // amounts from above.
        $floor = max(1, $certificate);
        $this->planAdvance = max($floor, min($this->planAdvance, $net - ($this->planCount - 1)));

        if ($this->planCount > 2) {
            $this->planSecondAmount = max(1, min($this->planSecondAmount, $net - $this->planAdvance - 1));
        } else {
            $this->planSecondAmount = 0;
        }
    }

    /**
     * The two parts, as Installments::schedule() wants them.
     *
     * The balance is derived, never typed, so the pair always sums to the fee
     * exactly - which is the one thing schedule() refuses outright.
     *
     * @return list<array{amount:int,due_date:string}>
     */
    private function planParts(): array
    {
        $net = $this->netFee();
        $floor = max(1, $this->certificateFee());
        $advance = max($floor, min($this->planAdvance, $net - ($this->planCount - 1)));

        if ($this->planCount < 3) {
            return [
                ['amount' => $advance, 'due_date' => $this->planFirstDue],
                ['amount' => $net - $advance, 'due_date' => $this->planSecondDue],
            ];
        }

        $second = max(1, min($this->planSecondAmount, $net - $advance - 1));

        return [
            ['amount' => $advance, 'due_date' => $this->planFirstDue],
            ['amount' => $second, 'due_date' => $this->planSecondDue],
            // Derived, so the three always sum to the fee exactly.
            ['amount' => $net - $advance - $second, 'due_date' => $this->planThirdDue],
        ];
    }

    /** What the registration will actually bill: tuition, discount, certificate. */
    public function netFee(): int
    {
        $base = $this->baseFee();

        return $base
            - ($this->discountPct > 0 ? (int) round($base * $this->discountPct / 100) : 0)
            + $this->certificateFee();
    }

    /**
     * The certificate charge this registration will carry.
     *
     * One per course, matching RegistrationService. Outside the discount, so
     * it is added after it in netFee() rather than folded into the base.
     */
    public function certificateFee(): int
    {
        return (int) config('institute.certificate_fee') * count($this->courseIds);
    }

    /** The fee before discount: each course priced by the modules taken. */
    public function baseFee(): int
    {
        return (int) Course::with('modules')->whereIn('id', $this->courseIds)->get()
            ->sum(fn (Course $c) => $c->priceFor($this->moduleIds[$c->id] ?? null));
    }

    public function back(): void { if ($this->step > 1) { $this->step--; } }

    /**
     * Courses the selected student already holds a live enrolment on.
     *
     * Marks the cards; it does not gate them. Empty for a brand new student,
     * who by definition is on nothing yet.
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
            // Only for the courses that are actually divided up. `moduleIds`
            // never holds a key for a course priced whole, and the service
            // refuses module ids sent against one.
            'modules' => $this->moduleIds,
            // Every selected course, so a cleared dropdown reaches the service
            // as an explicit "no batch" instead of falling back to the open
            // intake the officer just chose to leave.
            'cohorts' => collect($this->courseIds)
                ->mapWithKeys(fn ($id) => [$id => ($this->batchIds[$id] ?? '') === '' ? null : (int) $this->batchIds[$id]])
                ->all(),
            'plan' => $this->payPlan,
            'installments' => $this->payPlan === 'split' ? $this->planParts() : null,
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
            // back on. This used to lean on the unique live enrolment index
            // (BUG-17) to catch a repeated existing-student submit; that index
            // was lifted in 2026_09_17_000001, so this token is now the only
            // thing between a double-click and two of everything.
            // A brand new student always was a brand new row
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
            // `modules` and `cohorts` eager loaded: step 2 draws a fee, a
            // module list and a batch dropdown for every course in the
            // catalogue, and without these that is three queries per course
            // across forty-one of them on every keystroke of the filter.
            $activeCourses = Course::where('is_active', true)
                ->with(['admissions', 'modules', 'cohorts'])->orderBy('code')->get();
            $selectedCourses = $activeCourses->whereIn('id', $this->courseIds);
            // Priced by what is actually being bought, not by courses.fee -
            // which for a modular course is not the price of anything.
            $base = (int) $selectedCourses->sum(fn (Course $c) => $c->priceFor($this->moduleIds[$c->id] ?? null));
            $disc = $this->discountPct > 0 ? (int) round($base * $this->discountPct / 100) : 0;
            $nextAdm = (int) (Counter::where('key', 'admission')->value('value') ?? 12);
            $nextStud = (int) (Counter::where('key', 'student:'.$this->newType)->value('value') ?? 1);
            $count = max(1, $selectedCourses->count());
            // Formatted by Sequences, not by a second copy of the sprintf: a
            // preview that disagrees with what gets assigned is worse than none.
            $seq = \App\Services\Sequences::class;

            $wizard = [
                'enrolledCourseIds' => $this->enrolledCourseIds(),
                // Keyed by course so the step-2 card can draw its own batch
                // dropdown without a query of its own.
                'batchOptions' => $selectedCourses->mapWithKeys(fn (Course $c) => [
                    $c->id => $c->cohorts->where('is_open', true)->sortBy('name')->values(),
                ]),
                'certificateFee' => $this->certificateFee(),
                'planPreview' => $this->payPlan === 'split' && $this->netFee() > 1 ? $this->planParts() : null,
                'activeCourses' => $activeCourses,
                'selectedCourses' => $selectedCourses,
                // `net` is what the student actually owes: tuition, less the
                // discount, plus the certificate charge. The wizard's totals
                // and the challan it creates have to agree to the rupee.
                'base' => $base, 'disc' => $disc, 'net' => $base - $disc + $this->certificateFee(),
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
            // Same key the wizard already uses to agree a plan while raising
            // the challan; see SchedulesInstallments::plannableChallan().
            'canPlan' => $user->can('registrations.create'),
            'planChallan' => $this->planId ? $this->scopedChallans()->with('installments')->find($this->planId) : null,
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
            <x-ui.busy target="q" label="Searching…" />
            @if ($canCreate)
                <button class="btn btn-accent" wire:click="openWizard"><x-icon name="plus" :size="17" /> New Registration</button>
            @endif
        </div>

        <div class="panel scroll-x">
            <table class="table" wire:loading.class="is-busy" wire:target="q">
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
                        <x-ui.table-empty :cols="6" target="q">
                            No registrations match your filter.
                        </x-ui.table-empty>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.challan-drawer')
    @else
        @include('partials.registration-wizard')
    @endif
</div>
