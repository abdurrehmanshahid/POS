@php
    use App\Support\Format;

    // From the invoice, not through the admission. A charge has none, and the
    // chain left the payer's name and code blank on the one document that
    // exists to prove who handed money over.
    $student = $challan->student;
    $courses = $challan->admissions
        ->map(fn ($a) => $a->course)
        ->filter()
        ->unique('id');
    if ($courses->isEmpty() && $challan->admission?->course) {
        $courses = collect([$challan->admission->course]);
    }

    $contactEmail = config('institute.contact_email');
    $contactPhone = config('institute.contact_phone');

    // Embedded from the local filesystem, never a URL: dompdf runs with
    // `enable_remote => false`, so a receipt must render identically on a shared
    // host with no outbound network. `is_file()` so a missing asset degrades to
    // the wordmark rather than throwing while a parent waits at the counter.
    // The print-sized copy for the same reason the voucher uses it: dompdf
    // embeds the source bitmap once per placement.
    $logoFile = public_path('assets/bbt-logo-print.png');
    $hasLogo = is_file($logoFile);
    // A URL on screen, a filesystem path for dompdf. See the voucher template.
    $logo = ($forScreen ?? false) ? asset('assets/bbt-logo-print.png') : $logoFile;

    // Two copies on one A5 sheet: one for the student, one for the drawer. The
    // institute reconciles the till against paper at the end of the day, and a
    // single copy means the only evidence leaves with the payer.
    $copies = ['Student Copy', 'Office Copy'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $payment->receiptNo() }}</title>
    <style>
        /* DomPDF: table layout only, no flexbox or grid. */
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 8mm; }
        body { color: #12132a; font-size: 9px; margin: 0; }

        table.sheet { width: 100%; border-collapse: separate; border-spacing: 5px 0; table-layout: fixed; }
        td.copy { width: 50%; vertical-align: top; border: 1.2px solid #2A2668; padding: 0; }

        .head { text-align: center; padding: 6px 6px 5px; border-bottom: 1px solid #cfd4e6; }
        .copy-title { font-size: 9px; font-weight: bold; color: #2A2668; letter-spacing: .4px; text-transform: uppercase; }
        .logo { height: 26px; margin-top: 4px; }
        .wordmark { font-size: 13px; font-weight: bold; color: #2A2668; margin-top: 3px; }
        .institute { font-size: 8px; color: #555; margin-top: 2px; }

        /* The one line the whole document exists to state. */
        .banner { background: #F4F6FC; border-bottom: 1px solid #cfd4e6; padding: 7px 8px; text-align: center; }
        .banner-label { font-size: 7.5px; color: #555; text-transform: uppercase; letter-spacing: .5px; }
        .banner-amount { font-size: 17px; font-weight: bold; color: #12132a; margin-top: 2px; }
        .banner-words { font-size: 7.5px; color: #555; margin-top: 2px; font-style: italic; }

        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3px 8px; border-bottom: 1px solid #eef0f7; vertical-align: top; }
        td.k { color: #555; width: 42%; }
        td.v { color: #12132a; font-weight: bold; }

        .foot { padding: 6px 8px; }
        .sig { margin-top: 16px; border-top: 1px solid #999; padding-top: 3px; font-size: 7.5px; color: #555; width: 60%; }
        .note { font-size: 7px; color: #777; margin-top: 6px; line-height: 1.35; }
    </style>

    {{-- Screen only; dompdf never reaches this. See ChallanController::view()
         for why the "view" route serves a page rather than an inline PDF. --}}
    @if ($forScreen ?? false)
        <style>
            body { background: #eef0f6; padding: 18px; font-size: 12px; }
            .sheet-wrap { max-width: 900px; margin: 0 auto; background: #fff; padding: 16px;
                          box-shadow: 0 1px 3px rgba(0,0,0,.15); border-radius: 4px; }
            .bar { max-width: 900px; margin: 0 auto 14px; display: flex; align-items: center; gap: 10px; }
            .bar h1 { font-size: 15px; margin: 0; flex: 1; color: #12132a; }
            .bar button, .bar a {
                font: inherit; font-weight: 700; font-size: 12px; padding: 9px 16px; border-radius: 8px;
                border: 1px solid #cfd4e6; background: #fff; color: #2A2668; cursor: pointer; text-decoration: none;
            }
            .bar .primary { background: #2A2668; border-color: #2A2668; color: #fff; }

            @media (max-width: 760px) {
                table.sheet, table.sheet tr, table.sheet td.copy { display: block; width: auto; }
                td.copy { margin-bottom: 14px; }
            }

            @media print {
                @page { size: A5 landscape; margin: 8mm; }
                body { background: #fff; padding: 0; }
                .bar { display: none !important; }
                .sheet-wrap { max-width: none; margin: 0; padding: 0; box-shadow: none; border-radius: 0; }
                table.sheet, table.sheet tr, table.sheet td.copy { display: revert; }
                td.copy { margin-bottom: 0; }
            }
        </style>
    @endif
</head>
<body>
@if ($forScreen ?? false)
    <div class="bar">
        <h1>Receipt {{ $payment->receiptNo() }} · {{ $challan->student?->name }}</h1>
        <button class="primary" onclick="window.print()">Print</button>
        <a href="{{ route('payments.receipt', $payment) }}">Download PDF</a>
    </div>
    <div class="sheet-wrap">
@endif
<table class="sheet">
    <tr>
        @foreach ($copies as $copyTitle)
            <td class="copy">
                <div class="head">
                    <div class="copy-title">{{ $copyTitle }}</div>
                    @if ($hasLogo)
                        <img src="{{ $logo }}" alt="" class="logo">
                    @else
                        <div class="wordmark">{{ $settings->name }}</div>
                    @endif
                    <div class="institute">
                        {{ $settings->name }}
                        @if ($contactPhone) · {{ $contactPhone }} @endif
                        @if ($contactEmail) · {{ $contactEmail }} @endif
                    </div>
                    <div class="copy-title" style="margin-top:4px">Payment Receipt</div>
                </div>

                <div class="banner">
                    <div class="banner-label">Received with thanks</div>
                    <div class="banner-amount">{{ Format::money($payment->amount) }}</div>
                    <div class="banner-words">{{ $payment->method }}</div>
                </div>

                <table class="kv">
                    <tr><td class="k">Receipt No</td><td class="v">{{ $payment->receiptNo() }}</td></tr>
                    <tr><td class="k">Received on</td><td class="v">{{ Format::date($payment->received_at) }}</td></tr>
                    <tr><td class="k">{{ $challan->isCharge() ? 'Paid by' : 'Student' }}</td><td class="v">{{ $student?->name ?? '—' }}</td></tr>
                    <tr><td class="k">Reference</td><td class="v">{{ $student?->student_code ?? '—' }}</td></tr>
                    <tr>
                        {{-- A charge names the service it was for; there is no
                             course, and "Course: —" on a receipt for a room
                             booking says nothing about what was bought. --}}
                        <td class="k">{{ $challan->isCharge() ? 'For' : ($courses->count() > 1 ? 'Courses' : 'Course') }}</td>
                        <td class="v">{{ $challan->isCharge() ? $challan->subject() : ($courses->pluck('title')->implode(', ') ?: '—') }}</td>
                    </tr>
                    <tr><td class="k">Against challan</td><td class="v">{{ $challan->challan_no }}</td></tr>
                    <tr><td class="k">Total fee</td><td class="v">{{ Format::money($challan->net_amount) }}</td></tr>
                    {{-- Reconstructed as at THIS payment, never from today's ledger. A
                         reprint after a later collection must still agree with the copy
                         the student is holding. See ReceiptController::balanceAfter(). --}}
                    <tr><td class="k">Balance after this payment</td><td class="v">{{ Format::money($balanceAfter) }}</td></tr>
                    <tr><td class="k">Received by</td><td class="v">{{ $payment->receiver?->name ?? '—' }}</td></tr>
                </table>

                <div class="foot">
                    <div class="sig">Authorised signature</div>
                    <div class="note">
                        This receipt is evidence of the payment shown above and is valid
                        without a signature when issued by the system. The balance stated
                        is as at the date of this payment.
                    </div>
                </div>
            </td>
        @endforeach
    </tr>
</table>
@if ($forScreen ?? false)
    </div>
@endif
</body>
</html>
