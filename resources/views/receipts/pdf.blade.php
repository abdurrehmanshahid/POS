@php
    use App\Support\Format;

    $student = $challan->admission?->student;
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
    $logo = public_path('assets/bbt-logo-print.png');
    $hasLogo = is_file($logo);

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
</head>
<body>
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
                    <tr><td class="k">Student</td><td class="v">{{ $student?->name ?? '—' }}</td></tr>
                    <tr><td class="k">Student ID</td><td class="v">{{ $student?->student_code ?? '—' }}</td></tr>
                    <tr>
                        <td class="k">{{ $courses->count() > 1 ? 'Courses' : 'Course' }}</td>
                        <td class="v">{{ $courses->pluck('title')->implode(', ') ?: '—' }}</td>
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
</body>
</html>
