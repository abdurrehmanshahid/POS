<?php

use App\Models\Student;
use App\Services\Operations;
use App\Services\Sequences;
use App\Services\StudentService;
use App\Support\Format;
use App\Support\Concerns\GuardsDoubleSubmit;
use App\Support\Matcher;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    use GuardsDoubleSubmit;

    public string $q = '';

    public ?int $selectedId = null;

    public bool $drawerOpen = false;

    // ---- Add / edit student form ------------------------------------------
    public bool $formOpen = false;

    public ?int $editingId = null;   // null => creating

    public string $fType = 'R';

    public string $fName = '';

    public string $fGuardian = '';

    public string $fPhone = '';

    public string $fCnic = '';

    /**
     * `drawerOpen` and `selectedId` are two pieces of state describing one
     * thing, so they are only ever set together, and only for a student who
     * actually resolves through the visibility scope. When they drifted apart
     * the drawer rendered as an empty white panel with no header and therefore
     * no close button, because the shell was gated on the flag while its
     * contents were gated on the record.
     */
    public function viewStudent(int $id): void
    {
        if (! Student::visibleTo(auth()->user())->whereKey($id)->exists()) {
            return;
        }

        $this->selectedId = $id;
        $this->drawerOpen = true;
    }

    public function closeDrawer(): void
    {
        $this->drawerOpen = false;
        $this->selectedId = null;
    }

    public function mount(): void
    {
        // ?new=1 opens the form straight away, for the dashboard's quick action.
        // Silently ignored without the permission rather than aborting: a stale
        // bookmark should land on the list, not on a 403.
        if (request()->boolean('new') && auth()->user()->can('registrations.create')) {
            $this->newStudent();
        }
    }

    /**
     * Creating a student is part of enrolling one, so it rides on
     * `registrations.create`, the permission whose label is literally
     * "Create and enrol students". Editing an existing record changes an
     * identity already printed on issued challans, so it needs the narrower
     * `students.manage`, which only Administrator holds by default.
     */
    public function newStudent(): void
    {
        abort_unless(auth()->user()->can('registrations.create'), 403);
        $this->reset('editingId', 'fType', 'fName', 'fGuardian', 'fPhone', 'fCnic');
        $this->resetValidation();
        $this->formOpen = true;
        $this->freshOperationKey('student');
    }

    public function editStudent(int $id): void
    {
        abort_unless(auth()->user()->can('students.manage'), 403);

        // Scoped: an officer can only reach students they can already see.
        $student = Student::visibleTo(auth()->user())->findOrFail($id);

        $this->editingId = $student->id;
        $this->fType = $student->type;
        $this->fName = $student->name;
        // Coalesced because both columns are nullable and these props are typed
        // `string`: a student registered without a guardian threw a TypeError
        // here before the form could render, so the one screen that exists to
        // correct a record was a 500 for exactly the records most likely to
        // need correcting. Blank is turned back into NULL on save.
        $this->fGuardian = $student->guardian_name ?? '';
        $this->fPhone = $student->phone;
        $this->fCnic = $student->cnic ?? '';
        $this->resetValidation();
        $this->formOpen = true;
        $this->freshOperationKey('student');
    }

    public function saveStudent(): void
    {
        // The form has already been saved and closed, so there is nothing left
        // to save. Without this the screen is only ACCIDENTALLY safe: the reset
        // at the end of this method blanks `fName`, so the second half of a
        // bundled double-click fails validation and shows the officer "Cannot
        // save student · Please fix the highlighted fields" over a student who
        // was in fact added correctly. Anyone who later moves that reset, or
        // stops resetting the name, turns a confusing toast into a duplicate
        // person. See submit() in registrations.blade.php, where the same shape
        // did create two people, two admissions and two challans.
        if (! $this->formOpen) { return; }

        $service = app(StudentService::class);
        $payload = [
            'type' => $this->fType,
            'name' => $this->fName,
            'guardian_name' => $this->fGuardian,
            'phone' => $this->fPhone,
            'cnic' => $this->fCnic,
        ];

        $student = null;
        $replayed = false;

        try {
            if ($this->editingId) {
                abort_unless(auth()->user()->can('students.manage'), 403);
                $student = Student::visibleTo(auth()->user())->findOrFail($this->editingId);
                // Not guarded: an update writes the same values whichever click
                // wins, so a repeat is harmless. Creating is the dangerous one.
                $service->update(auth()->user(), $student, $payload);
                $msg = $student->student_code.' updated.';
            } else {
                abort_unless(auth()->user()->can('registrations.create'), 403);
                // Guarded because nothing else catches this. `student_code` is
                // freshly allocated on every call and `cnic` became optional in
                // BUG-23, so a double-click produced two people with the same
                // name and no constraint objected.
                $op = app(Operations::class)->once($this->operationKey('student'), 'student.create',
                    fn () => $service->create(auth()->user(), $payload));
                $replayed = $op->replayed;
                $student = $op->value;
                $msg = $replayed
                    ? trim($this->fName).' is already on file.'
                    : $student->name.' added as '.$student->student_code.'.';
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('f'.ucfirst($field === 'guardian_name' ? 'Guardian' : $field), $messages[0]);
            }
            $this->dispatch('bbt-toast', tone: 'err', title: 'Cannot save student', msg: 'Please fix the highlighted fields.');

            return;
        } catch (\Throwable $e) {
            // Broadened to match the other two write paths, which both catch
            // Throwable. A request arriving without an operation token makes
            // Operations::once() throw, and with only the ValidationException
            // arm above that surfaced as a 500 instead of a message.
            $this->dispatch('bbt-toast', tone: 'err', title: 'Cannot save student', msg: $e->getMessage());

            return;
        }

        $this->formOpen = false;
        // Null on a replay, and selecting nothing would close the drawer on a
        // student who is perfectly fine. Leave the selection where it was.
        if ($student) {
            $this->selectedId = $student->id;
        }
        $this->dispatch('bbt-toast',
            tone: 'ok',
            title: $this->editingId ? 'Student updated' : 'Student added',
            msg: $msg,
            note: $replayed ? 'Duplicate submission ignored' : null,
        );
        $this->reset('editingId', 'fType', 'fName', 'fGuardian', 'fPhone', 'fCnic');
        $this->freshOperationKey('student');
    }

    public function with(): array
    {
        $user = auth()->user();

        // Visible students, each with their scoped non-cancelled admissions
        // (officers only see enrolments they made) and the bits we render.
        $students = Student::visibleTo($user)
            ->with(['admissions' => fn ($q) => $q
                ->where('status', '!=', 'cancelled')
                ->when(! $user->can('scope.all'), fn ($qq) => $qq->where('enrolled_by', $user->id))
                ->with(['course', 'enroller', 'challan.installments', 'challan.payments'])])
            // The other half of what `outstanding()` reads: a contact's charges
            // hang off no admission, so without this the balance column costs a
            // query per row and a contact renders as a person with no money.
            ->withCharges()
            ->orderBy('name')
            ->get();

        // Advanced search over ID / name / CNIC / phone (spec §9.11).
        $matcher = new Matcher($this->q);
        $rows = $students->filter(function (Student $s) use ($matcher) {
            return $matcher->matches([
                '_all' => implode(' ', [$s->student_code, $s->name, $s->cnic, $s->phone, $s->guardian_name]),
                'id' => $s->student_code,
                'name' => $s->name,
                'cnic' => $s->cnic,
                'phone' => $s->phone,
            ]);
        })->values();

        return [
            'scopeLabel' => $user->can('scope.all') ? 'All students' : 'My students',
            'total' => $rows->count(),
            'rows' => $rows,
            // Selected from the full visible set so the drawer survives filtering.
            'selected' => $this->selectedId ? $students->firstWhere('id', $this->selectedId) : null,
            'canCreate' => $user->can('registrations.create'),
            'canEdit' => $user->can('students.manage'),
            // Live preview of the ID this student will be given (spec §7.3),
            // peeked without consuming the counter.
            'nextCode' => $this->editingId ? null : app(Sequences::class)->peekStudentCode($this->fType),
        ];
    }
}; ?>

<div class="container-app anim-fade">
    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px">
        <div>
            <h1 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);margin:0;letter-spacing:-.01em">{{ $scopeLabel }}</h1>
            <div class="tnum" style="font-size:var(--fs-sm);color:var(--muted);margin-top:3px">{{ $total }} total</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div class="search" style="width:320px;max-width:100%">
                <x-icon name="search" :size="15" />
                <input wire:model.live.debounce.200ms="q" placeholder="Search ID, name, CNIC, phone…" class="input">
            </div>
            <a href="{{ route('students.export') }}" class="btn btn-ghost"><x-icon name="download" :size="16" />Export Excel</a>
            @if ($canCreate)
                <button class="btn btn-accent" wire:click="newStudent"><x-icon name="plus" :size="16" />Add student</button>
            @endif
        </div>
    </div>

    {{-- Table --}}
    <div class="panel">
        <div class="scroll-x">
            <table class="table table-cards">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>CNIC</th>
                        <th>Enrolled by</th>
                        <th>Courses</th>
                        <th>Fees</th>
                        @if ($canEdit)<th class="right">Actions</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $s)
                        @php
                            $adms = $s->admissions;
                            $courses = $adms->count();
                            $recent = $adms->sortByDesc('id')->first();
                            $enrolledBy = $recent?->enroller?->name ?? 'Not enrolled';
                            $outstanding = $s->outstanding();
                            // Loaded by `withCharges()`, so this is only the
                            // invoices that bill no course. Someone can be
                            // billed without being enrolled, and the fees pill
                            // below has to know that or a contact who owes
                            // Rs 15,000 reads as having nothing to pay.
                            $charges = $s->challans;
                            $billed = $courses > 0 || $charges->isNotEmpty();
                        @endphp
                        <tr class="clickable" wire:click="viewStudent({{ $s->id }})">
                            <td class="tnum rec-id" data-label="ID" style="color:var(--iris);font-weight:700">{{ $s->student_code }}</td>
                            <td data-label="Student">
                                <div style="display:flex;align-items:center;gap:11px">
                                    <x-ui.avatar :name="$s->name" variant="orange" :size="30" />
                                    <div style="min-width:0">
                                        <div style="display:flex;align-items:center;gap:6px">
                                            <span style="font-weight:600;color:var(--ink)">{{ $s->name }}</span>
                                            {{-- Said outright, not left to the fine print. This person
                                                 bought a service and is not enrolled on anything, so
                                                 their absence from a register or a course count is
                                                 correct rather than a record somebody forgot to
                                                 finish. --}}
                                            @if ($s->isContact())
                                                <x-ui.pill tone="navy">Contact</x-ui.pill>
                                            @endif
                                        </div>
                                        <div style="font-size:var(--fs-xs);color:var(--muted)">{{ $s->guardian_name }} · {{ $s->typeLabel() }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="tnum" data-label="CNIC">{{ $s->cnic }}</td>
                            <td data-label="Enrolled by">{{ $enrolledBy }}</td>
                            <td class="tnum" data-label="Courses">{{ $courses }}</td>
                            <td data-label="Fees">
                                @if (! $billed)
                                    <x-ui.pill tone="cancelled">Nothing billed</x-ui.pill>
                                @elseif ($outstanding <= 0)
                                    <x-ui.pill tone="paid" :dot="true">Cleared</x-ui.pill>
                                @else
                                    <x-ui.pill tone="unpaid" :dot="true">Owes</x-ui.pill>
                                @endif
                            </td>
                            @if ($canEdit)
                                <td class="right" data-label="Actions">
                                    {{-- wire:click.stop so editing does not also open the drawer --}}
                                    <button class="btn btn-ghost btn-sm" wire:click.stop="editStudent({{ $s->id }})">
                                        <x-icon name="edit" :size="14" /> Edit
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canEdit ? 7 : 6 }}" class="empty-state">
                                @if ($q !== '')
                                    No students match your filter.
                                @else
                                    No students yet. @if ($canCreate)Use <strong>Add student</strong> to create the first record.@endif
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Student drawer. Gated on $selected, never on the flag alone: an open
         drawer with nothing to show is an unclosable white rectangle. --}}
    @if ($selected)
        <div x-data="{ open: @entangle('drawerOpen') }">
            <template x-if="open">
                <div>
                    <div class="drawer-backdrop" wire:click="closeDrawer"></div>
                    <div class="drawer">
                        @php
                            $recent = $selected->admissions->sortByDesc('id')->first();
                            $enrolledBy = $recent?->enroller?->name ?? 'Not enrolled';
                        @endphp
                        <div class="drawer-head">
                            <x-ui.avatar :name="$selected->name" variant="orange" :size="42" />
                            <div style="flex:1;min-width:0">
                                <div style="font-size:var(--fs-md);font-weight:800;color:var(--ink)">{{ $selected->name }}</div>
                                <div class="tnum" style="font-size:var(--fs-xs);color:var(--iris);font-weight:700;margin-top:2px">{{ $selected->student_code }}</div>
                            </div>
                            <button class="btn-icon" wire:click="closeDrawer"><x-icon name="x" :size="18" /></button>
                        </div>
                        <div class="drawer-body">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px 18px;margin-bottom:24px">
                                @foreach ([
                                    ['Guardian', $selected->guardian_name, false],
                                    ['Phone', $selected->phone, true],
                                    ['CNIC', $selected->cnic, true],
                                    ['Joined', Format::date($selected->created_at), false],
                                    ['Enrolled by', $enrolledBy, false],
                                ] as [$label, $value, $isNum])
                                    <div>
                                        <div style="font-size:var(--fs-2xs);text-transform:uppercase;letter-spacing:.05em;color:var(--faint);font-weight:700;margin-bottom:4px">{{ $label }}</div>
                                        <div @class(['tnum' => $isNum]) style="font-size:var(--fs-sm);color:var(--ink);font-weight:500">{{ $value ?: 'Not recorded' }}</div>
                                    </div>
                                @endforeach
                            </div>

                            <div style="font-size:var(--fs-2xs);text-transform:uppercase;letter-spacing:.05em;color:var(--faint);font-weight:700;margin-bottom:11px">Enrolments</div>
                            <div style="display:flex;flex-direction:column;gap:10px">
                                @forelse ($selected->admissions as $a)
                                    <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:12px">
                                        <div style="flex:1;min-width:0">
                                            <div style="font-size:var(--fs-sm);font-weight:600;color:var(--ink)">{{ $a->course?->title }}</div>
                                            <div class="tnum rec-id" style="font-size:var(--fs-xs);color:var(--muted);margin-top:1px">{{ $a->reg_no }}</div>
                                        </div>
                                        <div class="tnum" style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)">{{ Format::money($a->netShare()) }}</div>
                                        @if ($a->challan)
                                            @php $st = $a->challan->paymentState(); @endphp
                                            <x-ui.pill :tone="$st" :dot="true">{{ ucfirst($st) }}</x-ui.pill>
                                        @endif
                                    </div>
                                @empty
                                    <div class="empty-state">
                                        @if ($selected->isContact())
                                            Not enrolled on anything. This is a contact — someone the
                                            institute has billed for a service rather than a course.
                                            Enrolling them turns them into a student.
                                        @else
                                            No enrolments yet, this student is registered but not on a course.
                                        @endif
                                    </div>
                                @endforelse
                            </div>

                            {{-- Charges: what was billed with no course behind it. Shown
                                 only when there are some, so a normal student's drawer is
                                 unchanged. Without this a contact's entire financial
                                 history — the reason their record exists — is invisible on
                                 the one screen an officer opens to look them up. --}}
                            @if ($selected->challans->isNotEmpty())
                                <div style="font-size:var(--fs-2xs);text-transform:uppercase;letter-spacing:.05em;color:var(--faint);font-weight:700;margin:24px 0 11px">Charges</div>
                                <div style="display:flex;flex-direction:column;gap:10px">
                                    @foreach ($selected->challans->sortByDesc('id') as $c)
                                        <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:12px">
                                            <div style="flex:1;min-width:0">
                                                <div style="font-size:var(--fs-sm);font-weight:600;color:var(--ink)">{{ $c->subject() }}</div>
                                                <div class="tnum rec-id" style="font-size:var(--fs-xs);color:var(--muted);margin-top:1px">{{ $c->challan_no }} · {{ Format::date($c->created_at) }}</div>
                                            </div>
                                            <div class="tnum" style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)">{{ Format::money($c->net_amount) }}</div>
                                            @php $st = $c->paymentState(); @endphp
                                            <x-ui.pill :tone="$st" :dot="true">{{ ucfirst($st) }}</x-ui.pill>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Actions on the record itself --}}
                            <div style="display:flex;gap:10px;margin-top:24px;padding-top:18px;border-top:1px solid var(--border)">
                                @if ($canCreate)
                                    {{-- Straight into the wizard at step 2 with this student already
                                         chosen, so an existing record gains a course without any
                                         chance of creating a duplicate person. --}}
                                    <a href="{{ route('registrations', ['enrol' => $selected->id]) }}" wire:navigate
                                       class="btn btn-accent" style="flex:1">
                                        <x-icon name="plus" :size="15" /> Enrol in a course
                                    </a>
                                @endif
                                @if ($canEdit)
                                    <button class="btn btn-ghost" wire:click="editStudent({{ $selected->id }})">
                                        <x-icon name="edit" :size="15" /> Edit details
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif

    {{-- ---- Add / edit student drawer ------------------------------------ --}}
    <div x-data="{ open: @entangle('formOpen') }">
        <template x-if="open">
            <div>
                <div class="drawer-backdrop" @click="open=false"></div>
                <div class="drawer">
                    <div class="drawer-head">
                        <div class="drawer-head-icon"><x-icon name="students" :size="19" /></div>
                        <div class="drawer-head-text">
                            <div class="drawer-head-title">{{ $editingId ? 'Edit student' : 'Add student' }}</div>
                            <div class="drawer-head-sub">
                                {{ $editingId
                                    ? 'Identity changes are recorded in the audit trail.'
                                    : 'Creates the record only. Enrol in a course afterwards.' }}
                            </div>
                        </div>
                        <button class="btn-icon btn-icon-plain" @click="open=false" title="Close"><x-icon name="x" :size="17" /></button>
                    </div>

                    <div class="drawer-body">
                        <form wire:submit="saveStudent">
                            @if (! $editingId)
                                {{-- Series picker, with a live preview of the ID to assign (spec §7.3) --}}
                                <label class="label">Student type</label>
                                <div style="display:flex;gap:10px;margin-bottom:8px">
                                    @foreach ([['R', 'Regular', 'Regular courses'], ['T', 'Track', 'Track programmes']] as [$val, $title, $sub])
                                        <button type="button" wire:click="$set('fType','{{ $val }}')"
                                                style="flex:1;text-align:left;padding:12px 14px;border-radius:12px;cursor:pointer;border:1.5px solid {{ $fType === $val ? 'var(--iris)' : 'var(--border2)' }};background:{{ $fType === $val ? 'var(--iris-bg)' : 'var(--surface)' }}">
                                            <div style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)">{{ $title }}</div>
                                            <div style="font-size:var(--fs-2xs);color:var(--muted);margin-top:2px">{{ $sub }}</div>
                                        </button>
                                    @endforeach
                                </div>
                                <div style="display:flex;align-items:center;gap:8px;padding:10px 13px;background:var(--surface2);border:1px dashed var(--border2);border-radius:11px;margin-bottom:18px">
                                    <span style="font-size:var(--fs-2xs);font-weight:700;color:var(--faint);letter-spacing:.05em;text-transform:uppercase">ID to assign</span>
                                    <span class="tnum" style="font-size:var(--fs-base);font-weight:800;color:var(--iris)">{{ $nextCode }}</span>
                                </div>
                            @else
                                <div style="display:flex;align-items:center;gap:8px;padding:10px 13px;background:var(--surface2);border:1px dashed var(--border2);border-radius:11px;margin-bottom:18px">
                                    <span style="font-size:var(--fs-2xs);font-weight:700;color:var(--faint);letter-spacing:.05em;text-transform:uppercase">Student ID</span>
                                    <span class="tnum" style="font-size:var(--fs-base);font-weight:800;color:var(--iris)">{{ $selected?->student_code }}</span>
                                    <span style="font-size:var(--fs-2xs);color:var(--muted);margin-left:auto">Cannot be changed</span>
                                </div>
                            @endif

                            <label class="label">Student name</label>
                            <input wire:model="fName" type="text" class="input" placeholder="Full name" style="margin-bottom:4px">
                            @error('fName')<div style="font-size:var(--fs-2xs);color:var(--over);margin-bottom:8px">{{ $message }}</div>@enderror
                            <div style="height:12px"></div>

                            <label class="label">Guardian name</label>
                            <input wire:model="fGuardian" type="text" class="input" placeholder="Father / guardian" style="margin-bottom:4px">
                            @error('fGuardian')<div style="font-size:var(--fs-2xs);color:var(--over);margin-bottom:8px">{{ $message }}</div>@enderror
                            <div style="height:12px"></div>

                            <label class="label">Phone</label>
                            <input wire:model="fPhone" type="text" inputmode="numeric" maxlength="18"
                                   x-data x-on:input="window.bbtApplyMask($el, window.bbtMaskPhone)"
                                   class="input tnum" placeholder="+92 300 1234567" style="margin-bottom:4px">
                            @error('fPhone')<div style="font-size:var(--fs-2xs);color:var(--over);margin-bottom:8px">{{ $message }}</div>@enderror
                            <div style="height:12px"></div>

                            <label class="label">CNIC / B-Form</label>
                            <input wire:model="fCnic" type="text" inputmode="numeric" maxlength="15"
                                   x-data x-on:input="window.bbtApplyMask($el, window.bbtMaskCnic)"
                                   class="input tnum" placeholder="35201-1234567-1" style="margin-bottom:4px">
                            @error('fCnic')<div style="font-size:var(--fs-2xs);color:var(--over);margin-bottom:8px">{{ $message }}</div>@enderror

                            <div style="display:flex;gap:10px;margin-top:24px">
                                <button type="button" class="btn btn-ghost" style="flex:0 0 auto" @click="open=false">Cancel</button>
                                {{-- Narrows the double-click window; the token in
                                     Operations::once() is what actually closes it. --}}
                                <button type="submit" class="btn btn-accent" style="flex:1"
                                        wire:loading.attr="disabled" wire:target="saveStudent">
                                    {{ $editingId ? 'Save changes' : 'Add student' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
