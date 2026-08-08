<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Fee Challan {{ $challan->challan_no }}</title>
    @php
        use App\Support\Format;

        $adm = $challan->admission;
        $student = $adm?->student;
        $course = $adm?->course;
        $cohort = $adm?->cohort;

        $advance = $challan->paidAmount();
        $balance = $challan->balance();
        $paid = $challan->isPaid();

        // Discount is stored as an amount; the invoice the institute already
        // uses states a percentage, so derive it rather than print a second
        // number nobody at the counter recognises.
        $discountPct = $challan->base_amount > 0
            ? round($challan->discount_amount / $challan->base_amount * 100, 2)
            : 0;

        // The method shown is the most recent collection, which is what the
        // person holding the voucher just did.
        $method = $challan->payments->sortByDesc('received_at')->first()?->method ?? $challan->paid_via;

        $copies = ['Student Copy', 'Head Office Copy', 'Campus Copy'];

        // Every course this invoice bills, not just the one that heads it. An
        // invoice covering three Shopify levels has to name all three or the
        // student cannot tell what they paid for. Falls back to the anchor's
        // course so a challan raised before grouped invoicing still prints.
        // Cancelled enrolments are excluded: a course the student dropped has
        // no business on the voucher they are handed to pay with.
        $billedCourses = $challan->admissions
            ->where('status', '!=', 'cancelled')
            ->pluck('course')
            ->filter();
        if ($billedCourses->isEmpty() && $course) {
            $billedCourses = collect([$course]);
        }

        $contactEmail = config('institute.contact_email');
        $contactPhone = config('institute.contact_phone');

        // Embedded from the local filesystem, never a URL: dompdf runs with
        // `enable_remote => false`, and a voucher must render identically on a
        // shared host with no outbound network. Guarded with is_file() so a
        // missing asset degrades to the wordmark below rather than throwing
        // while a parent is waiting at the counter for their copy.
        // A print-sized copy, not the screen logo. dompdf embeds the source
        // bitmap once per placement, and this voucher places it three times, so
        // the screen asset produced a ~900 KB PDF for one fee challan, on a
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
                    <tr class="total"><td class="k" style="color:#2A2668;font-weight:bold">Net Payable</td><td class="v" style="color:#2A2668">{{ Format::money($challan->net_amount) }}</td></tr>
                    {{-- Rendered as a list because the institute's invoice bills
                         several courses together on one document. This challan
                         covers exactly one enrolment, so it lists one; the
                         markup is ready for the grouped invoice without a
                         second template. --}}
                    <tr><td class="k">Course(s)</td><td class="v">
                        @foreach ($billedCourses as $line)
                            <div><span class="bullet">•</span> {{ $line->title }}
                                <span style="font-weight:normal;color:#6b7192">({{ $line->code }})</span></div>
                        @endforeach
                    </td></tr>
                    <tr><td class="k">Batch</td><td class="v">{{ $cohort?->name ?? '—' }}</td></tr>
                    <tr><td class="k">Advance Payment</td><td class="v">{{ Format::money($advance) }}</td></tr>
                    <tr class="{{ $balance > 0 ? 'over' : '' }}"><td class="k">Balance</td><td class="v">{{ Format::money($balance) }}</td></tr>
                    <tr><td class="k">Due Date</td><td class="v">{{ Format::date($challan->due_date) }}</td></tr>
                    <tr><td class="k">Officer</td><td class="v">{{ $adm?->enroller?->name }}</td></tr>
                    <tr><td class="k">Admission #</td><td class="v tnum">{{ $adm?->reg_no }}<span style="font-weight:normal;color:#6b7192"> · {{ $student?->student_code }}</span></td></tr>
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

                <div class="sign">
                    <div class="sign-line">Signature</div>
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
