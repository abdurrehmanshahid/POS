{{-- 3-step New Registration wizard (spec §9.3). Uses the registrations component's
     props/methods: step, mode, newType, new*, courseIds, discountPct, discountReason,
     genChallans, wizErrors; toggleCourse, pickStudent, next, back, submit, closeWizard. --}}
@php use App\Support\Format; use App\Services\Sequences; @endphp

{{-- Widened with the type scale: a fixed 900px column that looked generous on a
     laptop is a narrow ribbon adrift in the middle of a 27" display. --}}
<div class="anim-fade" style="max-width:min(1120px,94vw);margin:0 auto">
    {{-- Progress --}}
    <div style="display:flex;align-items:center;gap:0;margin-bottom:24px">
        @foreach (['Student', 'Course & fee', 'Review'] as $i => $label)
            @php $n = $i + 1; $done = $step > $n; $active = $step === $n; @endphp
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:var(--fs-sm);font-weight:800;{{ $active ? 'background:var(--navy);color:#fff' : ($done ? 'background:var(--paid);color:#fff' : 'background:var(--surface3);color:var(--faint)') }}">{{ $done ? '✓' : $n }}</div>
                <span style="font-size:var(--fs-sm);font-weight:600;color:{{ $active || $done ? 'var(--ink)' : 'var(--faint)' }}">{{ $label }}</span>
            </div>
            @if ($n < 3)<div style="flex:1;height:2px;margin:0 14px;background:{{ $done ? 'var(--paid)' : 'var(--border2)' }}"></div>@endif
        @endforeach
    </div>

    <div class="card" style="padding:24px 26px">
        {{-- ===================== STEP 1: STUDENT ===================== --}}
        @if ($step === 1)
            <h2 style="font-size:var(--fs-lg);font-weight:800;color:var(--ink);margin:0 0 16px">Who is enrolling?</h2>
            <div style="display:inline-flex;background:var(--surface3);border-radius:11px;padding:4px;margin-bottom:20px">
                <button wire:click="$set('mode','existing')" class="btn btn-sm {{ $mode === 'existing' ? 'btn-primary' : '' }}" style="{{ $mode === 'existing' ? '' : 'background:transparent;color:var(--ink2)' }}">Existing student</button>
                <button wire:click="$set('mode','new')" class="btn btn-sm {{ $mode === 'new' ? 'btn-primary' : '' }}" style="{{ $mode === 'new' ? '' : 'background:transparent;color:var(--ink2)' }}">Add new student</button>
            </div>

            @if ($mode === 'existing')
                <div class="search" style="margin-bottom:8px"><x-icon name="search" :size="15" /><input wire:model.live.debounce.200ms="studentSearch" class="input" placeholder="Search by name, CNIC or student ID…"></div>
                <div style="font-size:var(--fs-xs);color:var(--faint);margin-bottom:14px">Pick from existing records to avoid duplicate students.</div>
                @if ($wizErrors['student'] ?? false)<div class="field-error" style="margin-bottom:10px">{{ $wizErrors['student'] }}</div>@endif
                <div style="display:flex;flex-direction:column;gap:8px">
                    @forelse ($matches as $m)
                        <button wire:click="pickStudent({{ $m->id }})" style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1.5px solid {{ $pickedStudentId === $m->id ? 'var(--iris)' : 'var(--border2)' }};background:{{ $pickedStudentId === $m->id ? 'var(--iris-bg)' : 'var(--surface)' }};border-radius:12px;cursor:pointer;text-align:left">
                            <x-ui.avatar :name="$m->name" variant="orange" :size="34" />
                            <div style="flex:1"><div style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)">{{ $m->name }}</div><div class="tnum" style="font-size:var(--fs-2xs);color:var(--muted)">{{ $m->student_code }} · {{ $m->cnic }}</div></div>
                            @if ($pickedStudentId === $m->id)<x-icon name="check-circle" :size="18" style="color:var(--iris)" />@endif
                        </button>
                    @empty
                        <div class="empty-state" style="padding:20px">Type to search existing students.</div>
                    @endforelse
                </div>
            @else
                <div style="display:flex;gap:10px;margin-bottom:18px">
                    <button wire:click="$set('newType','R')" style="flex:1;padding:14px;border:1.5px solid {{ $newType === 'R' ? 'var(--iris)' : 'var(--border2)' }};background:{{ $newType === 'R' ? 'var(--iris-bg)' : 'var(--surface)' }};border-radius:12px;cursor:pointer;text-align:left"><div style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)">Regular</div><div class="tnum" style="font-size:var(--fs-2xs);color:var(--muted)">{{ Sequences::prefix() }}R26-####</div></button>
                    <button wire:click="$set('newType','T')" style="flex:1;padding:14px;border:1.5px solid {{ $newType === 'T' ? 'var(--iris)' : 'var(--border2)' }};background:{{ $newType === 'T' ? 'var(--iris-bg)' : 'var(--surface)' }};border-radius:12px;cursor:pointer;text-align:left"><div style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)">Track</div><div class="tnum" style="font-size:var(--fs-2xs);color:var(--muted)">{{ Sequences::prefix() }}T26-####</div></button>
                </div>
                <div style="padding:10px 14px;background:var(--iris-bg);border-radius:10px;margin-bottom:18px;font-size:var(--fs-xs);color:var(--iris);font-weight:600">ID to assign: <span class="tnum">{{ $studentCodePreview }}</span></div>
                {{-- Every field answers as it is typed. Phone and CNIC are masked
                     into the exact shape the server stores, so the officer reads
                     back precisely what will be written. --}}
                {{-- Only name and phone are asked for. Guardian and CNIC are
                     marked optional rather than dropped, because they are worth
                     capturing when the officer has them; the schema stopped
                     requiring both in 2026_08_08_000001 and the counter has no
                     business demanding more than the database does. --}}
                <div class="grid-pair">
                    <div>
                        <label class="label">Student name</label>
                        <input wire:model.live.debounce.400ms="newName" wire:blur="touch('name')"
                               class="input {{ ($wizErrors['name'] ?? false) ? 'is-error' : '' }}" placeholder="Full name">
                        @if ($wizErrors['name'] ?? false)<span class="field-error">{{ $wizErrors['name'] }}</span>@endif
                    </div>
                    <div>
                        <label class="label">Phone</label>
                        <input wire:model.live.debounce.400ms="newPhone" wire:blur="touch('phone')"
                               inputmode="numeric" maxlength="18"
                               x-data x-on:input="window.bbtApplyMask($el, window.bbtMaskPhone)"
                               class="input {{ ($wizErrors['phone'] ?? false) ? 'is-error' : '' }}" placeholder="+92 3XX XXXXXXX">
                        @if ($wizErrors['phone'] ?? false)<span class="field-error">{{ $wizErrors['phone'] }}</span>@endif
                    </div>
                    {{-- The suggestion list needed a way out. It closed only when
                         the typed name became an exact match, so otherwise it sat
                         open over the Cancel button and the CNIC-clash card, where
                         a click picked a guardian instead of doing what was aimed
                         at. Escape and clicking away now dismiss it, and typing
                         again brings it back. Dismissal is local browser state, so
                         it is Alpine's, and the list itself stays server-rendered
                         rather than moving into a <template>, which races the
                         Livewire morph. --}}
                    {{-- Keyboard-driven, because a counter operator entering a
                         family's details is already typing and should not have to
                         reach for the mouse for the one field with a picker. Down
                         and Up move the highlight, Enter accepts, Escape closes.
                         Enter is only intercepted while something is highlighted,
                         so it stays available to whatever else wants it. --}}
                    <div class="field-suggest"
                         x-data="{
                             open: true,
                             i: -1,
                             items() { return [...$el.querySelectorAll('.suggest-item')] },
                             move(d) {
                                 const n = this.items().length;
                                 if (! n) return;
                                 this.open = true;
                                 this.i = (this.i + d + n) % n;
                             },
                             choose() { this.items()[this.i]?.click(); this.i = -1; },
                         }"
                         @click.outside="open = false" @keydown.escape.window="open = false; i = -1">
                        <label class="label">Guardian name <span class="label-opt">Optional</span></label>
                        <input wire:model.live.debounce.300ms="newGuardian" class="input"
                               autocomplete="off" placeholder="Guardian / parent"
                               role="combobox" aria-expanded="true" aria-controls="guardian-suggest"
                               @input="open = true; i = -1"
                               @keydown.arrow-down.prevent="move(1)"
                               @keydown.arrow-up.prevent="move(-1)"
                               @keydown.enter="if (i >= 0) { $event.preventDefault(); choose() }">
                        {{-- Siblings share a guardian, so the second child's form
                             is mostly a retype of the first. Picking a match fills
                             the number too. --}}
                        @if (! empty($guardianMatches))
                            <div class="suggest anim-fade" x-show="open" x-cloak
                                 id="guardian-suggest" role="listbox">
                                @foreach ($guardianMatches as $g)
                                    {{-- Ternary, not `cond && 'is-active'`: that yields
                                         boolean false when the condition fails and Alpine
                                         then clears the whole class attribute. --}}
                                    <button type="button" class="suggest-item" role="option"
                                            :class="i === {{ $loop->index }} ? 'is-active' : ''"
                                            :aria-selected="i === {{ $loop->index }}"
                                            @mouseenter="i = {{ $loop->index }}"
                                            wire:click="useGuardian(@js($g['name']), @js($g['phone']))">
                                        <x-icon name="users" :size="14" style="color:var(--faint);flex:none" />
                                        <span class="suggest-name">{{ $g['name'] }}</span>
                                        @if ($g['phone'] !== '')
                                            <span class="suggest-meta tnum">{{ $g['phone'] }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div>
                        <label class="label">CNIC / B-Form <span class="label-opt">Optional</span></label>
                        <input wire:model.live.debounce.400ms="newCnic" wire:blur="touch('cnic')"
                               inputmode="numeric" maxlength="15"
                               x-data x-on:input="window.bbtApplyMask($el, window.bbtMaskCnic)"
                               class="input {{ ($wizErrors['cnic'] ?? false) ? 'is-error' : '' }}" placeholder="#####-#######-#">
                        @if ($wizErrors['cnic'] ?? false)
                            <span class="field-error">{{ $wizErrors['cnic'] }}</span>
                        @else
                            <span class="field-hint">Recommended — it is how a returning student is recognised.</span>
                        @endif
                    </div>
                </div>

                {{-- A CNIC already on file means the person is already a student.
                     Offer them rather than blocking, since "back for another
                     course" is overwhelmingly the reason this happens. --}}
                @if ($cnicClash)
                    <div class="anim-fade" style="display:flex;align-items:center;gap:13px;margin-top:16px;padding:14px 16px;border:1.5px solid var(--due);background:var(--due-bg);border-radius:13px">
                        <x-ui.avatar :name="$cnicClash->name" variant="orange" :size="38" />
                        <div style="flex:1;min-width:0">
                            <div style="font-size:var(--fs-xs);font-weight:800;color:var(--due);letter-spacing:.02em;text-transform:uppercase">Already registered</div>
                            <div style="font-size:var(--fs-base);font-weight:700;color:var(--ink);margin-top:2px">{{ $cnicClash->name }}</div>
                            <div class="tnum" style="font-size:var(--fs-xs);color:var(--muted)">{{ $cnicClash->student_code }} · {{ $cnicClash->cnic }}</div>
                        </div>
                        <button class="btn btn-accent" style="flex:none" wire:click="useExistingStudent">
                            <x-icon name="check" :size="15" /> Enrol this student
                        </button>
                    </div>
                @endif
            @endif
        @endif

        {{-- ===================== STEP 2: COURSE & FEE ===================== --}}
        @if ($step === 2)
            <h2 style="font-size:var(--fs-lg);font-weight:800;color:var(--ink);margin:0 0 4px">Choose courses &amp; fee</h2>
            <p style="font-size:var(--fs-sm);color:var(--muted);margin:0 0 18px">Select one or more active courses. One admission &amp; challan is created per course.</p>
            @if ($wizErrors['courses'] ?? false)<div class="field-error" style="margin-bottom:10px">{{ $wizErrors['courses'] }}</div>@endif
            <div class="grid-2" style="margin-bottom:20px">
                @foreach ($activeCourses as $c)
                    @php
                        $sel = in_array($c->id, $courseIds);
                        $full = $c->isFull();
                        $left = $c->seatsLeft();
                        // Enrolling the same person on the same course twice
                        // creates two challans for one seat, so the card says so
                        // rather than letting it happen and failing on submit.
                        $already = in_array($c->id, $enrolledCourseIds, true);
                        $blocked = $full || $already;
                    @endphp
                    <div wire:click="toggleCourse({{ $c->id }})" style="padding:14px;border:1.5px solid {{ $sel ? 'var(--iris)' : 'var(--border2)' }};background:{{ $sel ? 'var(--iris-bg)' : 'var(--surface)' }};border-radius:12px;cursor:{{ $blocked ? 'not-allowed' : 'pointer' }};opacity:{{ $blocked ? '.55' : '1' }}">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start">
                            <div><div class="tnum" style="font-size:var(--fs-2xs);font-weight:700;color:var(--iris)">{{ $c->code }}</div><div style="font-size:var(--fs-sm);font-weight:700;color:var(--ink);margin-top:2px">{{ $c->title }}</div></div>
                            @if ($sel)<x-icon name="check-circle" :size="18" style="color:var(--iris)" />@endif
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:10px;font-size:var(--fs-2xs);color:var(--muted)">
                            <span>{{ $c->trainer?->name }}</span>
                            <span @if ($already) style="color:var(--due);font-weight:700" @endif>
                                {{ $already ? 'Already enrolled' : ($full ? 'Course full' : ($left === null ? 'Open' : $left.' seats left')) }}
                            </span>
                        </div>
                        <div class="tnum" style="font-size:var(--fs-base);font-weight:800;color:var(--navy);margin-top:8px">{{ Format::money($c->fee) }}</div>
                    </div>
                @endforeach
            </div>

            <div class="card" style="padding:16px 18px;background:var(--surface2)">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <label class="label" style="margin:0">Discount</label>
                    <span class="tnum" style="font-size:var(--fs-sm);font-weight:700;color:var(--orange)">{{ $discountPct }}%</span>
                </div>
                <input type="range" min="0" max="100" step="5" wire:model.live="discountPct" style="width:100%;accent-color:var(--orange)">
                <div class="grid-3" style="margin-top:14px;gap:12px">
                    <div><div style="font-size:var(--fs-2xs);color:var(--faint);text-transform:uppercase;letter-spacing:.04em">Base</div><div class="tnum" style="font-size:var(--fs-md);font-weight:800;color:var(--ink)">{{ Format::money($base) }}</div></div>
                    <div><div style="font-size:var(--fs-2xs);color:var(--faint);text-transform:uppercase;letter-spacing:.04em">Discount</div><div class="tnum" style="font-size:var(--fs-md);font-weight:800;color:var(--over)">− {{ Format::money($disc) }}</div></div>
                    <div><div style="font-size:var(--fs-2xs);color:var(--faint);text-transform:uppercase;letter-spacing:.04em">Net payable</div><div class="tnum" style="font-size:var(--fs-md);font-weight:800;color:var(--navy)">{{ Format::money($net) }}</div></div>
                </div>
                @if ($discountPct > 0)
                    <div style="margin-top:14px">
                        <label class="label">Discount reason (required · recorded in audit trail)</label>
                        <input wire:model="discountReason" class="input {{ ($wizErrors['discount'] ?? false) ? 'is-error' : '' }}" placeholder="e.g. Sibling discount, merit scholarship">
                        @if ($wizErrors['discount'] ?? false)<span class="field-error">{{ $wizErrors['discount'] }}</span>@endif
                    </div>
                @endif
            </div>
        @endif

        {{-- ===================== STEP 3: REVIEW ===================== --}}
        @if ($step === 3)
            <h2 style="font-size:var(--fs-lg);font-weight:800;color:var(--ink);margin:0 0 18px">Review &amp; confirm</h2>
            <div style="background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:14px;padding:18px 20px;color:#fff;margin-bottom:18px">
                <div style="display:flex;justify-content:space-between">
                    <div><div style="font-size:var(--fs-2xs);color:#b9bcdd">Student ID to assign</div><div class="tnum" style="font-size:var(--fs-lg);font-weight:800">{{ $mode === 'existing' ? ($pickedStudent?->student_code ?? '') : $studentCodePreview }}</div></div>
                    <div style="text-align:right"><div style="font-size:var(--fs-2xs);color:#b9bcdd">Admission # to be assigned</div><div class="tnum" style="font-size:var(--fs-lg);font-weight:800">{{ $admPreview }}</div></div>
                </div>
                <div style="margin-top:8px;font-size:var(--fs-xs);color:#dfe0f2">{{ $mode === 'existing' ? $pickedStudent?->name : $newName }}</div>
            </div>

            <div class="panel" style="margin-bottom:16px">
                <div class="panel-head" style="padding:14px 16px"><h3 class="panel-title" style="font-size:var(--fs-sm)">Charges · derived</h3></div>
                <div style="padding:6px 16px 14px">
                    @foreach ($selectedCourses as $c)
                        <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:var(--fs-sm);border-bottom:1px solid var(--surface3)"><span style="color:var(--ink2)">{{ $c->title }} <span class="tnum" style="color:var(--faint)">({{ $c->code }})</span></span><span class="tnum" style="font-weight:600">{{ Format::money($c->fee) }}</span></div>
                    @endforeach
                    <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:var(--fs-sm)"><span style="color:var(--muted)">Combined base</span><span class="tnum" style="font-weight:600">{{ Format::money($base) }}</span></div>
                    @if ($disc > 0)
                        <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:var(--fs-sm)"><span style="color:var(--muted)">Discount ({{ $discountPct }}%)</span><span class="tnum" style="font-weight:600;color:var(--over)">− {{ Format::money($disc) }}</span></div>
                    @endif
                    <div style="display:flex;justify-content:space-between;padding:10px 0 4px;font-size:var(--fs-md);border-top:2px solid var(--border)"><span style="font-weight:800;color:var(--ink)">Total net payable (derived)</span><span class="tnum" style="font-weight:800;color:var(--navy)">{{ Format::money($net) }}</span></div>
                </div>
            </div>

            @if ($selectedCourses->count() > 1)
                <div style="padding:11px 14px;background:var(--info-bg);border-radius:10px;font-size:var(--fs-xs);color:var(--info);margin-bottom:14px">One admission number &amp; challan is generated per course, so each enrolment stays independently auditable.</div>
            @endif
            <label style="display:flex;align-items:center;gap:9px;font-size:var(--fs-sm);color:var(--ink2);cursor:pointer"><input type="checkbox" wire:model="genChallans" style="width:16px;height:16px;accent-color:var(--iris)">Generate fee challan(s) on submit</label>
        @endif
    </div>

    {{-- Footer --}}
    <div style="display:flex;gap:10px;margin-top:18px">
        <button class="btn btn-ghost" wire:click="closeWizard">Cancel</button>
        <div style="flex:1"></div>
        @if ($step > 1)<button class="btn btn-ghost" wire:click="back"><x-icon name="arrow-left" :size="16" /> Back</button>@endif
        @if ($step < 3)
            <button class="btn btn-primary" wire:click="next">Continue <x-icon name="arrow-right" :size="16" /></button>
        @else
            <button class="btn btn-accent" wire:click="submit">Register student</button>
        @endif
    </div>
</div>
