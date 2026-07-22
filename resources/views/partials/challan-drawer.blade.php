{{--
  Shared challan/registration drawer + mark-paid dialog + cancel dialog.
  The including Livewire component must define: public props drawerId, payId,
  payMethod, cancelAdmId, cancelReason, cancelError; methods closeDrawer,
  askPay, confirmPay, askCancel, confirmCancel; and pass view vars
  $selected, $payChallan, $canPay, $canCancel, $payMethod, $cancelAdmId, $cancelError.
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
    @php $a = $selected->admission; [$tone, $label] = $pill($selected->paymentState()); @endphp
    <div class="drawer-backdrop" wire:click="closeDrawer"></div>
    <div class="drawer">
        <div class="drawer-head">
            <div style="flex:1">
                <div style="display:flex;align-items:center;gap:10px"><span class="tnum" style="font-size:16px;font-weight:800;color:var(--ink)">{{ $selected->challan_no }}</span><x-ui.pill :tone="$tone" :dot="true">{{ $label }}</x-ui.pill>@if ($a->status === 'cancelled')<x-ui.pill tone="cancelled">Cancelled</x-ui.pill>@endif</div>
                <div style="font-size:12.5px;color:var(--muted);margin-top:3px">Registration &amp; fee ledger</div>
            </div>
            <button class="btn-icon" wire:click="closeDrawer"><x-icon name="x" :size="18" /></button>
        </div>
        <div class="drawer-body">
            <div class="card" style="padding:16px;margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
                    <x-ui.avatar :name="$a->student->name" variant="orange" :size="40" />
                    <div><div style="font-size:15px;font-weight:700;color:var(--ink)">{{ $a->student->name }}</div><div class="tnum" style="font-size:12px;font-weight:700;color:var(--iris)">{{ $a->student->student_code }} · {{ $a->reg_no }}</div></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12.5px">
                    <div><div style="color:var(--faint)">Course</div><div style="color:var(--ink);font-weight:600">{{ $a->course->title }} ({{ $a->course->code }})</div></div>
                    <div><div style="color:var(--faint)">Trainer</div><div style="color:var(--ink);font-weight:600">{{ $a->course->trainer?->name ?? 'None' }}</div></div>
                    <div><div style="color:var(--faint)">Enrolled by</div><div style="color:var(--ink);font-weight:600">{{ $a->enroller->name }}</div></div>
                    <div><div style="color:var(--faint)">Due date</div><div class="tnum" style="color:var(--ink);font-weight:600">{{ Format::date($selected->due_date) }}</div></div>
                </div>
                @if ($a->status === 'cancelled' && $a->rejection_reason)
                    <div style="margin-top:12px;padding:10px 12px;background:var(--over-bg);border:1px solid var(--over-br);border-radius:10px;font-size:12px;color:var(--over)">Cancelled · {{ $a->rejection_reason }}</div>
                @endif
            </div>

            <div class="panel" style="margin-bottom:16px">
                <div class="panel-head" style="padding:14px 16px"><h3 class="panel-title" style="font-size:13.5px">Fee ledger · derived</h3></div>
                <div style="padding:6px 16px 14px">
                    <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:13px"><span style="color:var(--muted)">Base amount</span><span class="tnum" style="font-weight:600">{{ Format::money($selected->base_amount) }}</span></div>
                    @if ($selected->discount_amount > 0)
                        <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:13px;border-top:1px solid var(--surface3)"><span style="color:var(--muted)">Discount<div style="font-size:11px;color:var(--faint)">{{ $selected->discount_reason }} · approved by {{ $selected->discountApprover?->name }}</div></span><span class="tnum" style="font-weight:600;color:var(--over)">− {{ Format::money($selected->discount_amount) }}</span></div>
                    @endif
                    <div style="display:flex;justify-content:space-between;padding:10px 0 4px;font-size:14px;border-top:2px solid var(--border)"><span style="font-weight:700;color:var(--ink)">Net payable</span><span class="tnum" style="font-weight:800;color:var(--navy)">{{ Format::money($selected->net_amount) }}</span></div>

                    {{-- Collections, one line per handover of money. --}}
                    @foreach ($selected->payments->sortBy('received_at') as $p)
                        <div style="display:flex;justify-content:space-between;padding:7px 0;font-size:12.5px;border-top:1px solid var(--surface3)">
                            <span style="color:var(--muted)">{{ Format::date($p->received_at) }} · {{ $p->method }}<div style="font-size:11px;color:var(--faint)">received by {{ $p->receiver?->name ?? 'system' }}</div></span>
                            <span class="tnum" style="font-weight:600;color:var(--paid)">{{ Format::money($p->amount) }}</span>
                        </div>
                    @endforeach

                    @if ($selected->balance() > 0 && $selected->paidAmount() > 0)
                        <div style="display:flex;justify-content:space-between;padding:9px 0 4px;font-size:13.5px;border-top:1px solid var(--border)"><span style="font-weight:700;color:var(--due)">Balance due</span><span class="tnum" style="font-weight:800;color:var(--due)">{{ Format::money($selected->balance()) }}</span></div>
                    @endif

                    @if ($selected->isPaid())
                        <div style="margin-top:8px;font-size:12px;color:var(--paid);font-weight:600">Settled {{ Format::date($selected->paid_at) }} via {{ $selected->paid_via }}</div>
                    @endif
                </div>
            </div>

            <div class="panel">
                <div class="panel-head" style="padding:14px 16px"><h3 class="panel-title" style="font-size:13.5px">Audit history</h3></div>
                <div style="padding:8px 16px 14px">
                    @foreach ($selected->auditLogs->sortBy('created_at') as $log)
                        <div style="display:flex;gap:10px;padding:9px 0;border-top:1px solid var(--surface3)">
                            <div style="width:8px;height:8px;border-radius:50%;background:var(--iris);margin-top:5px;flex:0 0 auto"></div>
                            <div style="flex:1">
                                <div style="font-size:12.5px;font-weight:600;color:var(--ink)">{{ $log->action }}</div>
                                <div class="tnum" style="font-size:11.5px;color:var(--muted)">{{ $log->field }}: {{ $log->old_value }} → {{ $log->new_value }}</div>
                                <div style="font-size:11px;color:var(--faint)">{{ $log->actor?->name }} · {{ Format::date($log->created_at) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="drawer-foot">
            @if ($canPay && ! $selected->isPaid() && $a->status !== 'cancelled')
                <button class="btn btn-accent" wire:click="askPay({{ $selected->id }})"><x-icon name="check" :size="16" /> Mark paid</button>
            @endif
            <a class="btn btn-ghost" href="{{ route('challans.pdf', $selected) }}" target="_blank"><x-icon name="download" :size="16" /> Challan PDF</a>
            @if ($canCancel && $a->status !== 'cancelled')
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
                <h3 style="font-size:17px;font-weight:800;color:var(--ink);margin:0 0 4px">Record payment</h3>
                <p style="font-size:13px;color:var(--muted);margin:0 0 16px">Confirm collection for <b class="tnum">{{ $payChallan->challan_no }}</b>.</p>
                <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:16px">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span style="font-size:12.5px;color:var(--muted)">Net payable</span>
                        <span class="tnum" style="font-size:15px;font-weight:700;color:var(--ink2)">{{ Format::money($payChallan->net_amount) }}</span>
                    </div>
                    @if ($payChallan->paidAmount() > 0)
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
                            <span style="font-size:12.5px;color:var(--muted)">Already received</span>
                            <span class="tnum" style="font-size:15px;font-weight:700;color:var(--paid)">{{ Format::money($payChallan->paidAmount()) }}</span>
                        </div>
                    @endif
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;padding-top:8px;border-top:1px solid var(--border)">
                        <span style="font-size:12.5px;color:var(--muted)">Outstanding balance</span>
                        <span class="tnum" style="font-size:18px;font-weight:800;color:var(--navy)">{{ Format::money($payChallan->balance()) }}</span>
                    </div>
                </div>

                {{-- Pre-filled with the full balance. Editing it down records an
                     advance and leaves the challan open for the remainder. --}}
                <div class="label">Amount received</div>
                <input type="number" wire:model.live="payAmount" min="1" max="{{ $payChallan->balance() }}"
                       class="input tnum" style="margin-bottom:4px">
                @if ($payAmount > 0 && $payAmount < $payChallan->balance())
                    <div style="font-size:11.5px;color:var(--due);font-weight:600;margin-bottom:14px">
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
                    <button class="btn btn-primary" wire:click="confirmPay" @disabled(! $payMethod || $payAmount < 1 || $payAmount > $payChallan->balance())>Confirm payment</button>
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
                <h3 style="font-size:17px;font-weight:800;color:var(--ink);margin:0 0 4px">Cancel registration</h3>
                <p style="font-size:13px;color:var(--muted);margin:0 0 16px">This soft-deletes the enrolment and voids its challan. A reason is required.</p>
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
