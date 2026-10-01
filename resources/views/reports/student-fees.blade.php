<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Student fee report · {{ $period->rangeLabel() }}</title>
    @php
        use App\Support\Format;

        // A filesystem path, never a URL: dompdf runs with remote access off.
        // See challans/pdf for why it is the print-sized bitmap.
        $logo = public_path('assets/bbt-logo-print.png');
        $hasLogo = is_file($logo);

        // Bare grouped figures in the table; the currency is stated once in
        // the header, so fourteen columns are not each wearing "Rs ".
        $n = fn ($v) => number_format((int) $v);
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%';

        $tone = [
            'Paid' => 'paid', 'Installment' => 'part', 'Advance' => 'part',
            'Overdue' => 'over', 'Unpaid' => 'unpaid',
        ];
    @endphp
    <style>
        /* DomPDF: table layout only, no flexbox or grid. */
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 12mm 10mm 16mm; }
        body { color: #12132a; font-size: 8px; margin: 0; }

        /* Fixed footer on every page: who and when on the left, page on the right. */
        .page-foot { position: fixed; bottom: -10mm; left: 0; right: 0; height: 8mm;
                     border-top: .6px solid #cfd4e6; padding-top: 4px; font-size: 6.8px; color: #8a90ad; }
        .page-foot .pg:after { content: "Page " counter(page); }

        table.head { width: 100%; border-collapse: collapse; border-bottom: 2px solid #2A2668; }
        table.head td { vertical-align: bottom; padding-bottom: 8px; }
        .logo { height: 38px; }
        .brand { font-size: 15px; font-weight: bold; color: #2A2668; }
        .brand-sub { font-size: 7.5px; color: #6b7192; margin-top: 2px; }
        .doc-title { font-size: 17px; font-weight: bold; color: #12132a; text-align: right; letter-spacing: .3px; }
        .doc-meta { font-size: 7.5px; color: #6b7192; text-align: right; line-height: 1.6; margin-top: 3px; }
        .doc-meta b { color: #12132a; }

        table.kpis { width: 100%; border-collapse: separate; border-spacing: 5px 0; margin: 10px -5px 10px; }
        table.kpis td { border: .8px solid #cfd4e6; border-top: 2.5px solid #2A2668; padding: 6px 8px; width: 14.28%; }
        .kpi-label { font-size: 6.5px; color: #6b7192; text-transform: uppercase; letter-spacing: .5px; font-weight: bold; }
        .kpi-value { font-size: 11.5px; font-weight: bold; color: #12132a; margin-top: 3px; }
        .kpi-good { color: #1a7a4a; }
        .kpi-bad { color: #b00020; }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #2A2668; color: #fff; font-size: 6.8px; font-weight: bold; text-align: left;
                        padding: 5px 4px; border: .5px solid #2A2668; vertical-align: middle; }
        table.grid td { padding: 4px 4px; border: .5px solid #dfe2ee; vertical-align: top; font-size: 7.2px; }
        table.grid tbody tr:nth-child(even) td { background: #f6f7fb; }
        table.grid .r { text-align: right; white-space: nowrap; }
        table.grid .c { text-align: center; }
        table.grid .mono { white-space: nowrap; }
        table.grid .muted { color: #6b7192; }
        table.grid tr.total td { background: #eef0f7; font-weight: bold; color: #2A2668;
                                 border-top: 1.4px solid #2A2668; font-size: 7.6px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        .pill { font-size: 6.4px; font-weight: bold; padding: 1px 4px; border: .8px solid; border-radius: 2px; white-space: nowrap; }
        .paid { color: #1a7a4a; border-color: #1a7a4a; }
        .part { color: #a86000; border-color: #a86000; }
        .over { color: #b00020; border-color: #b00020; }
        .unpaid { color: #6b7192; border-color: #9aa0bd; }

        .empty { border: .8px dashed #cfd4e6; padding: 26px; text-align: center; color: #6b7192; font-size: 9px; margin-top: 10px; }

        .notes { margin-top: 10px; font-size: 6.8px; color: #6b7192; line-height: 1.6; }

        table.sign { width: 100%; margin-top: 34px; border-collapse: collapse; page-break-inside: avoid; }
        table.sign td { width: 33.33%; padding: 0 22px; text-align: center; }
        .sign-line { border-top: .8px solid #12132a; padding-top: 3px; font-size: 7.5px; color: #12132a; font-weight: bold; }
        .sign-sub { font-size: 6.5px; color: #8a90ad; font-weight: normal; }
    </style>
</head>
<body>

<div class="page-foot">
    <table style="width:100%;border-collapse:collapse"><tr>
        <td>{{ $settings->name }} · Student Fee Report · {{ $period->rangeLabel() }} · Confidential, for internal use</td>
        <td style="text-align:right"><span class="pg"></span></td>
    </tr></table>
</div>

{{-- Letterhead ------------------------------------------------------------- --}}
<table class="head">
    <tr>
        <td style="width:55%">
            <table style="border-collapse:collapse"><tr>
                @if ($hasLogo)
                    <td style="padding:0 10px 0 0;vertical-align:middle"><img src="{{ $logo }}" alt="" class="logo"></td>
                @endif
                <td style="padding:0;vertical-align:middle">
                    <div class="brand">{{ $settings->name }}</div>
                    <div class="brand-sub">
                        {{ config('institute.contact_email') }}@if (config('institute.contact_phone')) · {{ config('institute.contact_phone') }}@endif
                    </div>
                </td>
            </tr></table>
        </td>
        <td style="width:45%">
            <div class="doc-title">STUDENT FEE REPORT</div>
            <div class="doc-meta">
                Period: <b>{{ $period->label() }}</b> ({{ $period->rangeLabel() }})<br>
                Generated: <b>{{ Format::dateTime(now()) }}</b> {{ Format::zone() }} · Scope: <b>{{ $scope }}</b><br>
                All amounts in Pakistani Rupees (PKR)
            </div>
        </td>
    </tr>
</table>

@if (! $canSeeMoney)
    <div class="empty">Fee figures are not included: this account does not hold the revenue permission.</div>
@else
    {{-- Headline figures ------------------------------------------------------ --}}
    <table class="kpis">
        <tr>
            <td><div class="kpi-label">Students</div><div class="kpi-value">{{ $n($totals->students) }}</div></td>
            <td><div class="kpi-label">Total amount</div><div class="kpi-value">{{ $n($totals->total) }}</div></td>
            <td><div class="kpi-label">Discount given</div><div class="kpi-value">{{ $n($totals->discount) }} <span style="font-size:7.5px;color:#6b7192">({{ $pct($totals->discount_pct) }})</span></div></td>
            <td><div class="kpi-label">Net receivable</div><div class="kpi-value">{{ $n($totals->net) }}</div></td>
            <td><div class="kpi-label">Received</div><div class="kpi-value kpi-good">{{ $n($totals->received) }}</div></td>
            <td><div class="kpi-label">Pending</div><div class="kpi-value kpi-bad">{{ $n($totals->pending) }}</div></td>
            <td><div class="kpi-label">Receiving %</div><div class="kpi-value">{{ $pct($totals->received_pct) }}</div></td>
        </tr>
    </table>

    @if ($rows->isEmpty())
        <div class="empty">No registrations were invoiced in this period.</div>
    @else
        <table class="grid">
            <thead>
                <tr>
                    <th class="c" style="width:2.5%">#</th>
                    <th style="width:7%">Student ID</th>
                    <th style="width:10%">Student name</th>
                    <th style="width:10%">Father name</th>
                    <th style="width:8.5%">CNIC</th>
                    <th style="width:8%">Contact no</th>
                    <th style="width:13%">Course</th>
                    <th class="r" style="width:6.5%">Total amount</th>
                    <th class="r" style="width:6.5%">Discounted price</th>
                    <th class="r" style="width:4.5%">Disc. %</th>
                    <th class="r" style="width:6.5%">Advance / installment</th>
                    <th class="r" style="width:6.5%">Pending amount</th>
                    <th class="r" style="width:4.5%">Receiving %</th>
                    <th class="c" style="width:6%">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $i => $r)
                    <tr>
                        <td class="c muted">{{ $i + 1 }}</td>
                        <td class="mono">{{ $r->code }}</td>
                        <td>{{ $r->name }}</td>
                        <td>{{ $r->father ?: '—' }}</td>
                        <td class="mono">{{ $r->cnic ?: '—' }}</td>
                        <td class="mono">{{ $r->phone ?: '—' }}</td>
                        <td>{{ $r->course ?: '—' }}</td>
                        <td class="r">{{ $n($r->total) }}</td>
                        <td class="r">{{ $n($r->net) }}</td>
                        <td class="r">{{ $r->discount > 0 ? $pct($r->discount_pct) : '—' }}</td>
                        <td class="r">{{ $n($r->received) }}</td>
                        <td class="r" style="{{ $r->pending > 0 ? 'color:#b00020' : '' }}">{{ $n($r->pending) }}</td>
                        <td class="r">{{ $pct($r->received_pct) }}</td>
                        <td class="c"><span class="pill {{ $tone[$r->status] ?? 'unpaid' }}">{{ strtoupper($r->status) }}</span></td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="7">TOTAL · {{ $totals->students }} student{{ $totals->students === 1 ? '' : 's' }}, {{ $totals->count }} registration{{ $totals->count === 1 ? '' : 's' }}</td>
                    <td class="r">{{ $n($totals->total) }}</td>
                    <td class="r">{{ $n($totals->net) }}</td>
                    <td class="r">{{ $pct($totals->discount_pct) }}</td>
                    <td class="r">{{ $n($totals->received) }}</td>
                    <td class="r">{{ $n($totals->pending) }}</td>
                    <td class="r">{{ $pct($totals->received_pct) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <div class="notes">
            <b>Notes.</b> One line per registration invoice issued in the period; a student registered more than once appears once per invoice.
            Total amount is the course fee plus the certificate charge. Discounted price is the net payable after the discount.
            Discount % is taken on the course fee (the certificate charge is never discounted).
            Advance / installment is the money received so far, net of any reversals. Receiving % is received as a share of the discounted price.
        </div>
    @endif
@endif

<table class="sign">
    <tr>
        <td><div class="sign-line">Prepared by<br><span class="sign-sub">{{ $preparedBy }}</span></div></td>
        <td><div class="sign-line">Checked by (Accounts)<br><span class="sign-sub">&nbsp;</span></div></td>
        <td><div class="sign-line">Approved by<br><span class="sign-sub">&nbsp;</span></div></td>
    </tr>
</table>

</body>
</html>
