{{--
  Shared challan/registration drawer + mark-paid dialog + cancel dialog.
  The including Livewire component must define: public props drawerId, payId,
  payMethod, cancelAdmId, cancelReason, cancelError, reverseId, reverseAmount,
  reverseReason, reverseError; methods closeDrawer, askPay, confirmPay,
  askCancel, confirmCancel, askReverse, confirmReverse; the plan props
  planId, planAdvanceAmount, planDueFirst, planDueSecond, planError and methods
  askPlan, confirmPlan, clearPlan; and pass view vars
  $selected, $payChallan, $planChallan, $reversePayment, $canPay, $canCancel,
  $canReverse, $canPlan, $payMethod, $cancelAdmId, $cancelError,
  $reverseAmount, $reverseReason, $reverseError.

  The reversal half comes from the ReversesPayments trait, the payment half
  from CollectsPayments and the installment half from SchedulesInstallments —
  both screens include this file, so behaviour written into one component and
  not the other is how the two drift.
--}}
@php
    use App\Support\Format;
    $pill = fn ($s) => match ($s) {
        'paid' => ['paid', 'Paid'],
        'overdue' => ['overdue', 'Overdue'],
        default => ['unpaid', 'Unpaid'],
    };
@endphp

@if ($selected)
    {{-- `$a` is NULL for a charge — an invoice for a room booking or a
         certificate, which bills no enrolment. Every `$a->` below is therefore
         guarded, and the identity card has two branches rather than one with
         holes in it: a charge has no course, no trainer, no batch and no
         registration number, and printing those labels with dashes beside them
         would suggest a broken record rather than a different kind of one. --}}
    @php $a = $selected->admission; $isCharge = $selected->isCharge(); [$tone, $label] = $pill($selected->paymentState()); @endphp
    <div class="drawer-backdrop" wire:click="closeDrawer"></div>
    <div class="drawer">
        <div class="drawer-head">
            <div style="flex:1">
                <div style="display:flex;align-items:center;gap:10px"><span class="tnum" style="font-size:var(--fs-md);font-weight:800;color:var(--ink)">{{ $selected->challan_no }}</span><x-ui.pill :tone="$tone" :dot="true">{{ $label }}</x-ui.pill>@if ($a?->status === 'cancelled')<x-ui.pill tone="cancelled">Cancelled</x-ui.pill>@endif</div>
                <div style="font-size:var(--fs-xs);color:var(--muted);margin-top:3px">{{ $isCharge ? 'Charge & fee ledger' : 'Registration & fee ledger' }}</div>
            </div>
            <button class="btn-icon" wire:click="closeDrawer"><x-icon name="x" :size="18" /></button>
        </div>
        <div class="drawer-body">
            <div class="card" style="padding:16px;margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
                    <x-ui.avatar :name="$selected->student->name" variant="orange" :size="40" />
                    <div><div style="font-size:var(--fs-md);font-weight:700;color:var(--ink)">{{ $selected->student->name }}</div><div class="tnum" style="font-size:var(--fs-xs);font-weight:700;color:var(--iris)">{{ $selected->student->student_code }}@if ($a) · {{ $a->reg_no }}@endif</div></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:var(--fs-xs)">
                    @if ($isCharge)
                        <div><div style="color:var(--faint)">Charge for</div><div style="color:var(--ink);font-weight:600">{{ $selected->subject() }}</div></div>
                        <div><div style="color:var(--faint)">Raised by</div><div style="color:var(--ink);font-weight:600">{{ $selected->raiser?->name ?? 'Unknown' }}</div></div>
                        <div><div style="color:var(--faint)">Due date</div><div class="tnum" style="color:var(--ink);font-weight:600">{{ $selected->due_date ? Format::date($selected->due_date) : 'Not scheduled' }}</div></div>
                        <div><div style="color:var(--faint)">Enrolment</div><div style="color:var(--faint);font-weight:600">None — this is a service, not a course</div></div>
                    @else
                        <div><div style="color:var(--faint)">Course</div><div style="color:var(--ink);font-weight:600">{{ $a->course->title }} ({{ $a->course->code }})</div></div>
                        <div><div style="color:var(--faint)">Instructor</div><div style="color:var(--ink);font-weight:600">{{ $a->course->trainer?->name ?? 'None' }}</div></div>
                        <div><div style="color:var(--faint)">Enrolled by</div><div style="color:var(--ink);font-weight:600">{{ $a->enroller->name }}</div></div>
                        <div><div style="color:var(--faint)">Due date</div><div class="tnum" style="color:var(--ink);font-weight:600">{{ $selected->due_date ? Format::date($selected->due_date) : 'Not scheduled' }}</div></div>
                        <div><div style="color:var(--faint)">Batch</div><div style="color:{{ $a->cohort ? 'var(--ink)' : 'var(--faint)' }};font-weight:600">{{ $a->cohort?->name ?? 'No batch' }}</div></div>
                    @endif
                </div>
                @if ($a?->status === 'cancelled' && $a->rejection_reason)
                    <div style="margin-top:12px;padding:10px 12px;background:var(--over-bg);border:1px solid var(--over-br);border-radius:10px;font-size:var(--fs-xs);color:var(--over)">Cancelled · {{ $a->rejection_reason }}</div>
                @endif
            </div>

            <div class="panel" style="margin-bottom:16px">
                <div class="panel-head" style="padding:14px 16px"><h3 class="panel-title" style="font-size:var(--fs-sm)">Fee ledger · derived</h3></div>
                <div style="padding:6px 16px 14px">
                    <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:var(--fs-sm)"><span style="color:var(--muted)">Base amount</span><span class="tnum" style="font-weight:600">{{ Format::money($selected->base_amount) }}</span></div>
                    @if ($selected->discount_amount > 0)
                        <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:var(--fs-sm);border-top:1px solid var(--surface3)"><span style="color:var(--muted)">Discount<div style="font-size:var(--fs-2xs);color:var(--faint)">{{ $selected->discount_reason }} · approved by {{ $selected->discountApprover?->name }}</div></span><span class="tnum" style="font-weight:600;color:var(--over)">− {{ Format::money($selected->discount_amount) }}</span></div>
                    @endif
                    <div style="display:flex;justify-content:space-between;padding:10px 0 4px;font-size:var(--fs-base);border-top:2px solid var(--border)"><span style="font-weight:700;color:var(--ink)">Net payable</span><span class="tnum" style="font-weight:800;color:var(--navy)">{{ Format::money($selected->net_amount) }}</span></div>

                    {{-- Collections, one line per handover of money.

                         The GROSS amount is shown struck through when part or
                         all of it has been reversed, with the correction on its
                         own line beneath. Showing only the net would leave the
                         counter looking at a number that quietly disagrees with
                         the receipt in the student's hand and no way to explain
                         the difference — which is exactly the conversation a
                         cashier cannot afford to lose. --}}
                    @foreach ($selected->payments->sortBy('received_at') as $p)
                        @php $reversed = $p->reversedAmount(); @endphp
                        <div style="display:flex;justify-content:space-between;padding:7px 0;font-size:var(--fs-xs);border-top:1px solid var(--surface3)">
                            <span style="color:var(--muted)">{{ Format::date($p->received_at) }} · {{ $p->method }}<div style="font-size:var(--fs-2xs);color:var(--faint)">received by {{ $p->receiver?->name ?? 'system' }}</div></span>
                            <span style="display:flex;align-items:center;gap:8px">
                                @if ($reversed > 0)
                                    <span class="tnum" style="font-weight:600;color:var(--faint);text-decoration:line-through">{{ Format::money($p->amount) }}</span>
                                    <span class="tnum" style="font-weight:700;color:{{ $p->netAmount() > 0 ? 'var(--paid)' : 'var(--over)' }}">{{ Format::money($p->netAmount()) }}</span>
                                @else
                                    <span class="tnum" style="font-weight:600;color:var(--paid)">{{ Format::money($p->amount) }}</span>
                                @endif

                                {{-- Reversing is a supervisor act and the button
                                     only exists for one. Hidden rather than
                                     disabled: an officer has no use for an
                                     affordance they can never take. --}}
                                @if ($canReverse && $p->netAmount() > 0)
                                    <button wire:click="askReverse({{ $p->id }})"
                                            class="btn-icon" title="Reverse this payment (supervisor)">
                                        <x-icon name="reverse" :size="15" />
                                    </button>
                                @endif
                                {{-- One receipt per handover of money, not one per challan: a
                                     fee settled in three instalments is three receipts, and the
                                     student is entitled to the one for the money they just paid.
                                     `wire:navigate` is deliberately absent on both — these are
                                     PDF responses, and Livewire would try to render the bytes
                                     into the page. --}}
                                <a href="{{ route('payments.receipt.view', $p) }}" target="_blank"
                                   class="btn-icon" title="Open receipt {{ $p->receiptNo() }} to read or print"
                                   style="text-decoration:none">
                                    <x-icon name="eye" :size="15" />
                                </a>
                                <a href="{{ route('payments.receipt', $p) }}"
                                   class="btn-icon" title="Download receipt {{ $p->receiptNo() }}"
                                   style="text-decoration:none">
                                    <x-icon name="download" :size="15" />
                                </a>
                            </span>
                        </div>

                        {{-- One line per correction, naming who authorised it
                             and why. `payment_reversals` is append-only, so this
                             list only ever grows and is the whole audit story
                             for this handover, visible without leaving the
                             screen. --}}
                        @foreach ($p->reversals->sortBy('created_at') as $r)
                            <div style="display:flex;justify-content:space-between;padding:4px 0 6px 12px;font-size:var(--fs-2xs);color:var(--over)">
                                <span>Reversed {{ Format::date($r->created_at) }} · {{ $r->reason }}<div style="color:var(--faint)">approved by {{ $r->approver?->name ?? 'removed account' }}</div></span>
                                <span class="tnum" style="font-weight:600">− {{ Format::money($r->amount) }}</span>
                            </div>
                        @endforeach
                    @endforeach

                    @if ($selected->balance() > 0 && $selected->paidAmount() > 0)
                        <div style="display:flex;justify-content:space-between;padding:9px 0 4px;font-size:var(--fs-sm);border-top:1px solid var(--border)"><span style="font-weight:700;color:var(--due)">Balance due</span><span class="tnum" style="font-weight:800;color:var(--due)">{{ Format::money($selected->balance()) }}</span></div>
                    @endif

                    @if ($selected->isPaid())
                        <div style="margin-top:8px;font-size:var(--fs-xs);color:var(--paid);font-weight:600">Settled {{ Format::date($selected->paid_at) }} via {{ $selected->paid_via }}</div>
                    @endif
                </div>
            </div>

            <div class="panel">
                <div class="panel-head" style="padding:14px 16px"><h3 class="panel-title" style="font-size:var(--fs-sm)">Audit history</h3></div>
                <div style="padding:8px 16px 14px">
                    @foreach ($selected->auditLogs->sortBy('created_at') as $log)
                        <div style="display:flex;gap:10px;padding:9px 0;border-top:1px solid var(--surface3)">
                            <div style="width:8px;height:8px;border-radius:50%;background:var(--iris);margin-top:5px;flex:0 0 auto"></div>
                            <div style="flex:1">
                                <div style="font-size:var(--fs-xs);font-weight:600;color:var(--ink)">{{ $log->action }}</div>
                                <div class="tnum" style="font-size:var(--fs-2xs);color:var(--muted)">{{ $log->field }}: {{ $log->old_value }} → {{ $log->new_value }}</div>
                                <div style="font-size:var(--fs-2xs);color:var(--faint)">{{ $log->actor?->name }} · {{ Format::date($log->created_at) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            {{-- The fee schedule. A two-part plan existed in the database
                 and appeared on no screen, so an officer looking at a
                 part-paid challan could not tell whether the balance was
                 overdue or simply not due yet. --}}
            @php $schedule = $selected->installments->sortBy('seq')->values(); @endphp
            @if ($schedule->isNotEmpty())
                <div class="panel" style="margin-bottom:16px">
                    <div class="panel-head" style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center">
                        <h3 class="panel-title" style="font-size:var(--fs-sm)">Installment plan</h3>
                        @if ($canPlan && ! $selected->isPaid())
                            <div style="display:flex;gap:6px">
                                <button class="btn btn-sm btn-ghost" wire:click="askPlan({{ $selected->id }})">Edit</button>
                                <button class="btn btn-sm btn-ghost" wire:click="clearPlan({{ $selected->id }})">Remove</button>
                            </div>
                        @endif
                    </div>
                    <div style="padding:6px 16px 14px">
                        @foreach ($schedule as $part)
                            <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;font-size:var(--fs-sm){{ ! $loop->last ? ';border-bottom:1px solid var(--surface3)' : '' }}">
                                <span style="color:var(--ink2)">
                                    {{ (['1st', '2nd', '3rd'][$part->seq - 1] ?? $part->seq.'th').' installment' }}
                                    <span class="tnum" style="color:var(--faint)"> &middot; by {{ Format::date($part->due_date) }}</span>
                                </span>
                                <span style="display:flex;align-items:center;gap:10px">
                                    <span class="tnum" style="font-weight:700;color:var(--ink)">{{ Format::money($part->amount) }}</span>
                                    @if ($part->status === 'paid')
                                        <span class="pill pill-ok" style="font-size:var(--fs-2xs)">Paid</span>
                                    @else
                                        <span class="pill" style="font-size:var(--fs-2xs);color:var(--due)">Due</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                        {{-- Derived, not stored. Status comes from the ledger
                             on every collection, so this cannot disagree with
                             the money actually received. --}}
                        <p style="font-size:var(--fs-2xs);color:var(--muted);margin:10px 0 0">
                            Each installment is marked paid from the payments ledger, oldest first.
                        </p>
                    </div>
                </div>
            @endif
        </div>
        <div class="drawer-foot">
            @if ($canPay && ! $selected->isPaid() && $a?->status !== 'cancelled')
                <button class="btn btn-accent" wire:click="askPay({{ $selected->id }})"><x-icon name="check" :size="16" /> Mark paid</button>
            @endif
            {{-- Print first, save second. Printing a voucher is the common act
                 at a counter, and it used to mean download → find the file →
                 open → print → delete. This opens it in the browser's viewer,
                 where Ctrl+P is one key away and nothing lands in Downloads. --}}
            {{-- Offered only when there is no plan yet; editing one is done
                 from the panel above, next to the schedule it changes. --}}
            @if ($canPlan && $selected->installments->isEmpty() && ! $selected->isPaid() && $a?->status !== 'cancelled')
                <button class="btn btn-ghost" wire:click="askPlan({{ $selected->id }})"><x-icon name="clock" :size="16" /> Installment plan</button>
            @endif
            <a class="btn btn-ghost" href="{{ route('challans.view', $selected) }}" target="_blank"><x-icon name="printer" :size="16" /> View &amp; print</a>
            <a class="btn btn-ghost" href="{{ route('challans.pdf', $selected) }}"><x-icon name="download" :size="16" /> Download</a>
            {{-- Same predicate the server enforces in ChallanActions::cancel(), so
                 the button is never offered for an action that can only fail.
                 Absent entirely on a charge: cancelling means cancelling an
                 ENROLMENT, and a charge has none to cancel. --}}
            @if ($canCancel && $a && $a->status !== 'cancelled' && ! $selected->hasCollections())
                <button class="btn btn-danger" style="margin-left:auto" wire:click="askCancel({{ $a->id }})">Cancel</button>
            @endif
        </div>
    </div>
@endif

{{-- Mark paid dialog --}}
@if ($payChallan)
    <div class="dialog-backdrop" wire:click.self="$set('payId', null)">
        <div class="dialog">
            <div class="dialog-body">
                <h3 style="font-size:var(--fs-lg);font-weight:800;color:var(--ink);margin:0 0 4px">Record payment</h3>
                <p style="font-size:var(--fs-sm);color:var(--muted);margin:0 0 16px">Confirm collection for <b class="tnum">{{ $payChallan->challan_no }}</b>.</p>
                <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:16px">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span style="font-size:var(--fs-xs);color:var(--muted)">Net payable</span>
                        <span class="tnum" style="font-size:var(--fs-md);font-weight:700;color:var(--ink2)">{{ Format::money($payChallan->net_amount) }}</span>
                    </div>
                    @if ($payChallan->paidAmount() > 0)
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
                            <span style="font-size:var(--fs-xs);color:var(--muted)">Already received</span>
                            <span class="tnum" style="font-size:var(--fs-md);font-weight:700;color:var(--paid)">{{ Format::money($payChallan->paidAmount()) }}</span>
                        </div>
                    @endif
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;padding-top:8px;border-top:1px solid var(--border)">
                        <span style="font-size:var(--fs-xs);color:var(--muted)">Outstanding balance</span>
                        <span class="tnum" style="font-size:var(--fs-lg);font-weight:800;color:var(--navy)">{{ Format::money($payChallan->balance()) }}</span>
                    </div>
                </div>

                {{-- Pre-filled with the full balance. Editing it down records an
                     advance and leaves the challan open for the remainder. --}}
                <div class="label">Amount received</div>
                <input type="number" wire:model.live="payAmount" min="1" max="{{ $payChallan->balance() }}"
                       class="input tnum" style="margin-bottom:4px">
                @if ($payAmount > 0 && $payAmount < $payChallan->balance())
                    <div style="font-size:var(--fs-2xs);color:var(--due);font-weight:600;margin-bottom:14px">
                        Part payment · {{ Format::money($payChallan->balance() - $payAmount) }} will remain due
                    </div>
                @else
                    <div style="height:14px"></div>
                @endif

                <div class="label">Payment method</div>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px">
                    @foreach (config('institute.payment_methods') as $m)
                        <button wire:click="$set('payMethod', @js($m))" class="btn btn-sm {{ $payMethod === $m ? 'btn-accent' : 'btn-ghost' }}">{{ $m }}</button>
                    @endforeach
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end">
                    <button class="btn btn-ghost" wire:click="$set('payId', null)">Cancel</button>
                    {{-- wire:loading disables the button for the round trip, which
                         narrows the double-click window but does not close it: a
                         request already on the wire has already committed by the
                         time the browser could cancel it. The server-side token
                         in Operations::once() is the actual guard; this only
                         stops the officer from generating the second request in
                         the first place. --}}
                    <button class="btn btn-primary" wire:click="confirmPay"
                            wire:loading.attr="disabled" wire:target="confirmPay"
                            @disabled(! $payMethod || $payAmount < 1 || $payAmount > $payChallan->balance())>Confirm payment</button>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Reverse-payment dialog.

     Supervisor only, and shaped like a decision rather than a form: the
     original collection is restated in full at the top, because the whole risk
     here is reversing the payment next to the one you meant. --}}
@if ($reversePayment)
    @php $reversible = $reversePayment->amount - $reversePayment->reversedAmount(); @endphp
    <div class="dialog-backdrop" wire:click.self="$set('reverseId', null)">
        <div class="dialog">
            <div class="dialog-body">
                <h3 style="font-size:var(--fs-md);font-weight:700;color:var(--ink);margin:0 0 4px">Reverse a payment</h3>
                <p style="font-size:var(--fs-sm);color:var(--muted);margin:0 0 16px">
                    Receipt <b class="tnum">{{ $reversePayment->receiptNo() }}</b> ·
                    {{ $reversePayment->challan?->student?->name }}
                </p>

                <div style="background:var(--surface2);border-radius:10px;padding:12px 14px;margin-bottom:16px">
                    <div style="display:flex;justify-content:space-between;padding:3px 0;font-size:var(--fs-sm)">
                        <span style="color:var(--muted)">Collected {{ Format::date($reversePayment->received_at) }} · {{ $reversePayment->method }}</span>
                        <span class="tnum" style="font-weight:700;color:var(--ink2)">{{ Format::money($reversePayment->amount) }}</span>
                    </div>
                    @if ($reversePayment->reversedAmount() > 0)
                        <div style="display:flex;justify-content:space-between;padding:3px 0;font-size:var(--fs-sm)">
                            <span style="color:var(--muted)">Already reversed</span>
                            <span class="tnum" style="font-weight:700;color:var(--over)">− {{ Format::money($reversePayment->reversedAmount()) }}</span>
                        </div>
                    @endif
                    <div style="display:flex;justify-content:space-between;padding:6px 0 2px;border-top:1px solid var(--border);font-size:var(--fs-sm)">
                        <span style="font-weight:700;color:var(--ink)">Reversible</span>
                        <span class="tnum" style="font-weight:800;color:var(--navy)">{{ Format::money($reversible) }}</span>
                    </div>
                </div>

                <div class="label">Amount to reverse</div>
                <input type="number" wire:model.live="reverseAmount" min="1" max="{{ $reversible }}"
                       class="input tnum" style="margin-bottom:6px">
                @if ($reverseAmount > 0 && $reverseAmount < $reversible)
                    <div style="font-size:var(--fs-2xs);color:var(--due);font-weight:600;margin-bottom:14px">
                        Partial · {{ Format::money($reversible - $reverseAmount) }} of this payment will still stand
                    </div>
                @else
                    <div style="height:14px"></div>
                @endif

                <div class="label">Reason</div>
                {{-- Required, and not for tidiness. A correction with no stated
                     cause is indistinguishable from tampering to whoever reads
                     the ledger six months from now, and that person may be an
                     auditor. It is written verbatim into the activity log. --}}
                <input type="text" wire:model.live="reverseReason" maxlength="255"
                       class="input" placeholder="e.g. Amount mistyped at the counter — 50,000 entered for 5,000"
                       style="margin-bottom:14px">

                <div style="font-size:var(--fs-2xs);color:var(--muted);margin-bottom:16px;line-height:1.45">
                    The original payment and its receipt are <b>not deleted</b> — nothing here ever is.
                    This records an offsetting entry, every money total drops by it, and the receipt
                    reprints stamped as reversed. It cannot be undone; correcting a reversal means
                    taking a fresh payment.
                </div>

                @if ($reverseError)
                    <div style="background:var(--over-bg);border:1px solid var(--over-br);border-radius:8px;padding:9px 12px;margin-bottom:14px;font-size:var(--fs-xs);color:var(--over)">{{ $reverseError }}</div>
                @endif

                <div style="display:flex;gap:10px;justify-content:flex-end">
                    <button class="btn btn-ghost" wire:click="$set('reverseId', null)">Cancel</button>
                    <button class="btn btn-danger" wire:click="confirmReverse"
                            wire:loading.attr="disabled" wire:target="confirmReverse"
                            @disabled(trim($reverseReason) === '' || $reverseAmount < 1 || $reverseAmount > $reversible)>Reverse payment</button>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Cancel dialog --}}
@if ($cancelAdmId)
    <div class="dialog-backdrop" wire:click.self="$set('cancelAdmId', null)">
        <div class="dialog">
            <div class="dialog-body">
                <h3 style="font-size:var(--fs-lg);font-weight:800;color:var(--ink);margin:0 0 4px">Cancel registration</h3>
                <p style="font-size:var(--fs-sm);color:var(--muted);margin:0 0 16px">This soft-deletes the enrolment and voids its challan. A reason is required.</p>
                <div class="label">Reason</div>
                <textarea wire:model="cancelReason" class="textarea" placeholder="e.g. Duplicate enrolment, student withdrew…"></textarea>
                @if ($cancelError)<span class="field-error">{{ $cancelError }}</span>@endif
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                    <button class="btn btn-ghost" wire:click="$set('cancelAdmId', null)">Keep</button>
                    <button class="btn btn-danger" wire:click="confirmCancel">Cancel registration</button>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Installment plan dialog.

     The advance and the two dates are asked for; the balance is shown and
     never typed. That is what makes an invalid plan unreachable from this
     screen: `Installments::schedule()` refuses parts that do not sum to
     net_amount exactly, and a second typed amount is the only way to miss. --}}
@if ($planId && $planChallan)
    @php $planNet = (int) $planChallan->net_amount; @endphp
    <div class="dialog-backdrop" wire:click.self="$set('planId', null)">
        <div class="dialog">
            <div class="dialog-body">
                <h3 style="font-size:var(--fs-lg);font-weight:800;color:var(--ink);margin:0 0 4px">Installment plan</h3>
                <p style="font-size:var(--fs-sm);color:var(--muted);margin:0 0 16px">
                    {{ $planChallan->challan_no }} &middot; fee {{ Format::money($planNet) }}. The last installment is whatever is left.
                </p>

                <div style="display:flex;gap:8px;margin-bottom:14px">
                    <button type="button" wire:click="$set('planParts', 2)"
                            class="btn btn-sm {{ (int) $planParts === 2 ? 'btn-primary' : 'btn-ghost' }}">Two installments</button>
                    <button type="button" wire:click="$set('planParts', 3)"
                            class="btn btn-sm {{ (int) $planParts === 3 ? 'btn-primary' : 'btn-ghost' }}">Three installments</button>
                </div>

                <div class="grid-2" style="gap:12px">
                    <div>
                        <div class="label">1st installment{{ $planChallan->certificate_amount > 0 ? ' (incl. certificate)' : '' }}</div>
                        <input type="number" min="1" max="{{ max(1, $planNet - 1) }}" class="input tnum" wire:model.live.debounce.400ms="planAdvanceAmount">
                    </div>
                    <div>
                        <div class="label">Due by</div>
                        <input type="date" class="input tnum" wire:model="planDueFirst">
                    </div>
                </div>

                @if ((int) $planParts === 3)
                    <div class="grid-2" style="gap:12px;margin-top:12px">
                        <div>
                            <div class="label">2nd installment</div>
                            <input type="number" min="1" max="{{ max(1, $planNet - (int) $planAdvanceAmount - 1) }}"
                                   class="input tnum" wire:model.live.debounce.400ms="planSecondAmount">
                        </div>
                        <div>
                            <div class="label">Due by</div>
                            <input type="date" class="input tnum" wire:model="planDueSecond" min="{{ $planDueFirst }}">
                        </div>
                    </div>
                @endif

                <div class="grid-2" style="gap:12px;margin-top:12px">
                    <div>
                        <div class="label">{{ (int) $planParts === 3 ? '3rd' : '2nd' }} installment &middot; derived</div>
                        <div class="input tnum" style="background:var(--surface3);color:var(--ink);font-weight:700">
                            {{ Format::money(max(0, $planNet - (int) $planAdvanceAmount - ((int) $planParts === 3 ? (int) $planSecondAmount : 0))) }}
                        </div>
                    </div>
                    <div>
                        <div class="label">Due by</div>
                        <input type="date" class="input tnum"
                               wire:model="{{ (int) $planParts === 3 ? 'planDueThird' : 'planDueSecond' }}"
                               min="{{ (int) $planParts === 3 ? $planDueSecond : $planDueFirst }}">
                    </div>
                </div>

                @if ($planError)<span class="field-error">{{ $planError }}</span>@endif

                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                    <button class="btn btn-ghost" wire:click="$set('planId', null)">Cancel</button>
                    <button class="btn btn-accent" wire:click="confirmPlan">Save plan</button>
                </div>
            </div>
        </div>
    </div>
@endif
