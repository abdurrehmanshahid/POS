<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- Matches ChallanController::view()'s page title exactly. These are the
         same document reached two ways — the in-app viewer and the browser's
         own, on the saved file — and they were introducing it with different
         capitalisation and different detail. --}}
    <title>Fee challan {{ $challan->challan_no }}{{ $challan->student?->name ? ' · '.$challan->student->name : '' }}</title>
    @php
        use App\Support\Format;

        $adm = $challan->admission;
        // From the invoice, not through the admission. A charge — a room
        // booking, a certificate fee — has no admission, and reaching for the
        // student through one printed a voucher with the name, phone and CNIC
        // all blank: a document the counter would hand over with nobody on it.
        $student = $challan->student;
        $course = $adm?->course;
        $cohort = $adm?->cohort;

        // Ordered here rather than in the loop: the voucher prints three
        // copies of this table, and "Installment 2" appearing above
        // "Advance Payment" on a torn-off bank copy is not recoverable.
        $schedule = $challan->installments->sortBy('seq')->values();

        $advance = $challan->paidAmount();
        $balance = $challan->balance();
        $paid = $challan->isPaid();

        // Discount is stored as an amount; the invoice the institute already
        // uses states a percentage, so derive it rather than print a second
        // number nobody at the counter recognises.
        $discountPct = $challan->base_amount > 0
            ? round($challan->discount_amount / $challan->base_amount * 100, 2)
            : 0;

        // What actually happened, if anything has; otherwise what was agreed.
        //
        // That order matters on a reprint: once money has arrived the voucher
        // should say how it arrived, not how somebody expected it to. Before
        // then, the agreed method is the only thing there is — and it is the
        // line a parent reads to know how to pay, which is why it was worth a
        // column rather than leaving the field blank on every unpaid voucher.
        $method = $challan->payments->sortByDesc('received_at')->first()?->method
            ?? $challan->paid_via
            ?? $challan->payment_method;

        // The three copies every fee challan in Pakistan is printed with, in
        // the order they are torn off. This is not a naming preference — it is
        // how the document is USED, and the previous split (Student, Head
        // Office, Campus) gave the bank none at all:
        //
        //   Bank Copy       the student hands all three across the counter, the
        //                   bank stamps all three and RETAINS this one as its
        //                   record of the deposit.
        //   Student Copy    stamped and handed back. The payer's proof.
        //   Institute Copy  stamped and handed back, then submitted here. It is
        //                   what the office reconciles the day's takings
        //                   against, and it carries the bank's stamp, which is
        //                   the only part of this document the institute did
        //                   not print itself.
        //
        // A voucher with no bank copy is one a cashier cannot accept: there is
        // nothing to keep, so there is no record on the bank's side that the
        // money was ever deposited against this challan number.
        $copies = ['Bank Copy', 'Student Copy', 'Institute Copy'];

        // Every course this invoice bills, not just the one that heads it. An
        // invoice covering three Shopify levels has to name all three or the
        // student cannot tell what they paid for. Falls back to the anchor's
        // course so a challan raised before grouped invoicing still prints.
        // Cancelled enrolments are excluded: a course the student dropped has
        // no business on the voucher they are handed to pay with.
        // The ADMISSIONS, not their courses. The voucher has to name the batch
        // and the modules bought on each line, and both hang off the enrolment
        // — plucking the course threw away the only thing that knows them, so
        // a three-course invoice could only ever print the anchor's batch.
        $billedLines = $challan->admissions
            ->where('status', '!=', 'cancelled')
            ->filter(fn ($line) => $line->course !== null)
            ->values();
        if ($billedLines->isEmpty() && $adm && $course) {
            $billedLines = collect([$adm]);
        }

        // One batch for the whole invoice reads better on the institute's form
        // and is the overwhelmingly common case. Several, and each has to be
        // said against the course it belongs to or the row is a guess.
        $batches = $billedLines
            ->filter(fn ($line) => $line->cohort !== null)
            ->map(fn ($line) => $billedLines->count() > 1
                ? $line->course->code.' '.$line->cohort->name
                : $line->cohort->name)
            ->unique()
            ->values();

        $contactEmail = config('institute.contact_email');
        $contactPhone = config('institute.contact_phone');

        // A filesystem path, never a URL: dompdf runs with
        // `enable_remote => false`, and a voucher must render identically on a
        // shared host with no outbound network. Guarded with is_file() so a
        // missing asset degrades to the wordmark below rather than throwing
        // while a parent is waiting at the counter for their copy.
        //
        // The print-sized bitmap, not the screen one. dompdf embeds the source
        // image once per placement and this voucher places it three times, so
        // the screen asset produced a ~900 KB PDF for one fee challan — a
        // document that gets printed and WhatsApped to parents. At 140px tall
        // it is still comfortably above the 20px it renders at.
        $logo = public_path('assets/bbt-logo-print.png');
        $hasLogo = is_file($logo);
    @endphp
    <style>
        /* DomPDF: table layout only, no flexbox or grid. Landscape A4, three
           equal columns, each a self-contained voucher. */
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 12mm 8mm; }
        body { color: #12132a; font-size: 9px; margin: 0; }

        table.sheet { width: 100%; border-collapse: separate; border-spacing: 6px 0; table-layout: fixed; }
        td.copy { width: 33.33%; vertical-align: top; border: 1.2px solid #2A2668; padding: 0; }

        .copy-head { text-align: center; padding: 7px 6px 5px; border-bottom: 1px solid #cfd4e6; }
        .copy-title { font-size: 10px; font-weight: bold; color: #2A2668; letter-spacing: .4px; text-transform: uppercase; }
        /* Height only, so the mark keeps its aspect ratio on all three copies.
           A three-up landscape voucher has very little vertical room, so this
           sits deliberately below the copy title rather than competing with it. */
        .logo { height: 28px; margin-top: 5px; }
        .bullet { color: #6b7192; }
        .brand { font-size: 11px; font-weight: bold; color: #2A2668; margin-top: 1px; }
        .brand small { display: block; font-size: 7.5px; color: #6b7192; font-weight: normal; margin-top: 1px; }
        .docno { font-size: 10px; font-weight: bold; color: #12132a; margin-top: 4px; }

        .sec { font-size: 8px; font-weight: bold; color: #fff; background: #2A2668;
               padding: 3px 6px; letter-spacing: .3px; }

        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3px 6px; font-size: 8.2px; vertical-align: top; border-bottom: .5px solid #e9ebf3; }
        table.kv td.k { color: #6b7192; width: 42%; }
        table.kv td.v { font-weight: bold; color: #12132a; }
        table.kv tr.total td { border-top: 1px solid #2A2668; border-bottom: none;
                               font-size: 9.5px; color: #2A2668; padding-top: 5px; }
        .over td.v { color: #b00020; }

        .stamp { text-align: center; padding: 5px 6px 0; }
        .stamp span { display: inline-block; padding: 2px 10px; border: 1.2px solid; border-radius: 3px;
                      font-size: 9px; font-weight: bold; letter-spacing: .6px; }
        .paid { color: #1a7a4a; border-color: #1a7a4a; }
        .unpaid { color: #b00020; border-color: #b00020; }
        .part { color: #a86000; border-color: #a86000; }

        .sign { padding: 16px 8px 7px; }
        .sign-line { border-top: .8px solid #12132a; width: 62%; margin-left: 38%;
                     text-align: center; padding-top: 2px; font-size: 7.5px; color: #6b7192; }

        .foot { margin-top: 7px; text-align: center; font-size: 6.8px; color: #9aa0bd; line-height: 1.5; }
    </style>

</head>
<body>
<table class="sheet">
    <tr>
        @foreach ($copies as $copy)
            <td class="copy">
                <div class="copy-head">
                    <div class="copy-title">{{ $copy }}</div>
                    @if ($hasLogo)
                        <img src="{{ $logo }}" alt="" class="logo">
                    @endif
                    <div class="brand">{{ $settings->name }}<small>Fee Challan / Payment Voucher</small></div>
                    {{-- "Invoice #" is what the institute's existing paperwork
                         calls this, and what a student asks for at the counter.
                         The identifier itself stays fully qualified so two
                         years of numbering cannot collide. --}}
                    <div class="docno tnum">Invoice #: {{ $challan->challan_no }}</div>
                </div>

                <div class="sec">Bank Details</div>
                <table class="kv">
                    <tr><td class="k">A/C Title</td><td class="v">{{ $settings->name }}</td></tr>
                    <tr><td class="k">Bank Name</td><td class="v">{{ $settings->bank }}</td></tr>
                    <tr><td class="k">A/C #</td><td class="v">{{ $settings->account }}</td></tr>
                    <tr><td class="k">IBAN #</td><td class="v">{{ $settings->iban }}</td></tr>
                </table>

                <div class="sec">Student Details</div>
                <table class="kv">
                    {{-- Field order follows the institute's existing invoice
                         exactly, so counter staff read the same rows in the
                         same places they already know. --}}
                    <tr><td class="k">Name</td><td class="v">{{ $student?->name }}</td></tr>
                    <tr><td class="k">Father</td><td class="v">{{ $student?->guardian_name ?: '—' }}</td></tr>
                    <tr><td class="k">Phone</td><td class="v">{{ $student?->phone }}</td></tr>
                    <tr><td class="k">CNIC</td><td class="v">{{ $student?->cnic }}</td></tr>
                    <tr><td class="k">Payment Method</td><td class="v">{{ $method ?: '—' }}</td></tr>
                    <tr><td class="k">Issue Date</td><td class="v">{{ Format::date($challan->created_at) }}</td></tr>
                </table>

                <div class="sec">Fee Details</div>
                <table class="kv">
                    <tr><td class="k">Fee</td><td class="v">{{ Format::money($challan->base_amount) }}</td></tr>
                    @if ($challan->discount_amount > 0)
                        <tr><td class="k">Discount</td><td class="v">{{ rtrim(rtrim(number_format($discountPct, 2), '0'), '.') }}%<br><span style="font-weight:normal;color:#6b7192">{{ $challan->discount_reason }}</span></td></tr>
                    @endif
                    {{-- The certificate charge, on its own line and AFTER the
                         discount, because it is not discounted. A parent
                         checking the arithmetic on this voucher has to be able
                         to: tuition, less the discount, subtotal, plus the
                         certificate, total. Absent entirely on the challans
                         raised before the charge existed, which carry 0 and
                         must keep totalling what they always did. --}}
                    @if ($challan->certificate_amount > 0)
                        <tr><td class="k">Subtotal</td><td class="v">{{ Format::money($challan->base_amount - $challan->discount_amount) }}</td></tr>
                        <tr><td class="k">Certificate charges</td><td class="v">{{ Format::money($challan->certificate_amount) }}</td></tr>
                    @endif
                    <tr class="total"><td class="k" style="color:#2A2668;font-weight:bold">Net Payable</td><td class="v" style="color:#2A2668">{{ Format::money($challan->net_amount) }}</td></tr>
                    {{-- Rendered as a list because the institute's invoice bills
                         several courses together on one document. This challan
                         covers exactly one enrolment, so it lists one; the
                         markup is ready for the grouped invoice without a
                         second template. --}}
                    {{-- A charge bills no course, so it names what it IS
                         instead. Left as an empty "Course(s)" row the voucher
                         would show a fee with nothing beside it to say what the
                         money was for. --}}
                    <tr><td class="k">{{ $challan->isCharge() ? 'For' : 'Course(s)' }}</td><td class="v">
                        @if ($challan->isCharge())
                            <div><span class="bullet">•</span> {{ $challan->subject() }}</div>
                        @else
                            @foreach ($billedLines as $line)
                                @php $modules = $line->moduleLabel(); @endphp
                                <div><span class="bullet">•</span> {{ $line->course->title }}
                                    <span style="font-weight:normal;color:#6b7192">({{ $line->course->code }})</span>
                                    {{-- What of the course this student actually
                                         bought. Blank for a course priced whole,
                                         where the title already says all of it. --}}
                                    @if ($modules !== '')
                                        <div style="font-weight:normal;color:#6b7192;padding-left:9px">{{ $modules }}</div>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </td></tr>
                    @unless ($challan->isCharge())
                        <tr><td class="k">Batch</td><td class="v">{{ $batches->isNotEmpty() ? $batches->implode(', ') : '—' }}</td></tr>
                    @endunless
                    <tr><td class="k">Advance Payment</td><td class="v">{{ Format::money($advance) }}</td></tr>
                    <tr class="{{ $balance > 0 ? 'over' : '' }}"><td class="k">Balance</td><td class="v">{{ Format::money($balance) }}</td></tr>
                    {{-- A voucher handed to a parent must not show a blank where a deadline
                         belongs; an imported legacy balance may genuinely have none. --}}
                    {{-- The schedule, when there is one. Without this a
                         two-part plan printed a single total and a single
                         date — the LAST one — so the voucher asked a parent
                         for the whole fee on the day only the second half was
                         actually due. The plan existed in the database and
                         nowhere on the document the payer reads. --}}
                    @if ($schedule->isNotEmpty())
                        @foreach ($schedule as $part)
                            {{-- Not "Advance Payment": that label is taken, a
                                 few rows down, by the money actually received
                                 so far. Two rows under one name on a document
                                 a parent pays from is a demand they cannot
                                 read — one says 45,000, the other 0. --}}
                            <tr><td class="k">{{ (['1st', '2nd', '3rd'][$part->seq - 1] ?? $part->seq.'th').' Installment' }}</td><td class="v">
                                {{ Format::money($part->amount) }}
                                <span style="font-weight:normal;color:#6b7192">· by {{ Format::date($part->due_date) }}</span>
                                {{-- Said on the line that carries it. The
                                     charge is collected up front, and a payer
                                     comparing the two installments needs to
                                     know why the first is the larger one. --}}
                                @if ($part->seq === 1 && $challan->certificate_amount > 0)
                                    <div style="font-weight:normal;color:#6b7192">includes {{ Format::money($challan->certificate_amount) }} certificate charges</div>
                                @endif
                                @if ($part->status === 'paid')
                                    <span style="font-weight:normal;color:#0f7b4f">· received</span>
                                @endif
                            </td></tr>
                        @endforeach
                    @endif
                    <tr><td class="k">{{ $schedule->isNotEmpty() ? 'Settle in full by' : 'Due Date' }}</td><td class="v">{{ $challan->due_date ? Format::date($challan->due_date) : 'Not scheduled' }}</td></tr>
                    <tr><td class="k">Officer</td><td class="v">{{ $adm?->enroller?->name ?? $challan->raiser?->name }}</td></tr>
                    <tr><td class="k">{{ $challan->isCharge() ? 'Reference' : 'Admission #' }}</td><td class="v tnum">{{ $adm?->reg_no }}@if ($adm)<span style="font-weight:normal;color:#6b7192"> · </span>@endif<span style="font-weight:normal;color:#6b7192">{{ $student?->student_code }}</span></td></tr>
                </table>

                <div class="stamp">
                    @if ($paid)
                        <span class="paid">PAID</span>
                    @elseif ($advance > 0)
                        <span class="part">PART PAID</span>
                    @else
                        <span class="unpaid">UNPAID</span>
                    @endif
                </div>

                {{-- What the line is FOR, said plainly. On the standard form
                     this is where the cashier stamps and initials, and an
                     unstamped copy is worth nothing to anybody: it is the
                     bank's mark, not ours, that turns a demand into evidence
                     the money was deposited. Labelled "Signature" it read as a
                     place for the student to sign. --}}
                <div class="sign">
                    <div class="sign-line">Bank Stamp &amp; Signature</div>
                </div>
            </td>
        @endforeach
    </tr>
</table>

<div class="foot">
    System-generated voucher. The admission number is read from the record and cannot be altered.
    Verify payments at {{ $contactEmail }}@if ($contactPhone) · {{ $contactPhone }}@endif · generated {{ Format::date(now()) }}.
</div>
</body>
</html>
