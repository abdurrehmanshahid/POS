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
        $paid = $challan->status === 'paid';
    @endphp
    <style>
        /* DomPDF-friendly: table-based, no flexbox/grid. Amounts as Rs #,###. */
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #12132a; font-size: 12px; margin: 0; padding: 26px 30px; }
        .rule { border-bottom: 2px solid #2A2668; }
        .head { width: 100%; }
        .brand { font-size: 19px; font-weight: bold; color: #2A2668; }
        .brand small { display: block; font-size: 10px; color: #6b7192; font-weight: normal; margin-top: 2px; }
        .doc { text-align: right; }
        .doc .t { font-size: 15px; font-weight: bold; color: #2A2668; letter-spacing: .5px; }
        .doc .m { font-size: 11px; color: #6b7192; margin-top: 3px; }
        .doc .m b { color: #12132a; }
        table.kv { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.kv td { padding: 5px 8px; border: 1px solid #e4e7f0; font-size: 11.5px; vertical-align: top; }
        table.kv td.k { background: #f7f8fc; color: #6b7192; width: 130px; }
        table.kv td.v { font-weight: bold; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.lines th { background: #2A2668; color: #fff; text-align: left; padding: 8px 10px; font-size: 11px; }
        table.lines th.amt, table.lines td.amt { text-align: right; }
        table.lines td { padding: 8px 10px; border-bottom: 1px solid #e4e7f0; }
        table.lines tr.net td { font-weight: bold; font-size: 13px; color: #2A2668; border-top: 2px solid #2A2668; }
        .pay { width: 100%; margin-top: 20px; }
        .pay td { vertical-align: top; }
        .bank { font-size: 11.5px; color: #3a3f5c; line-height: 1.7; }
        .bank b { color: #12132a; }
        .stamp { display: inline-block; padding: 6px 16px; border: 2px solid; border-radius: 6px; font-size: 14px; font-weight: bold; letter-spacing: 1px; }
        .stamp.paid { color: #1a7a4a; border-color: #1a7a4a; }
        .stamp.unpaid { color: #b00020; border-color: #b00020; }
        .stub { width: 100%; border-collapse: collapse; margin-top: 26px; border: 1px dashed #b7bdd4; }
        .stub th { background: #f7f8fc; color: #6b7192; text-align: left; padding: 6px 8px; font-size: 9.5px; text-transform: uppercase; }
        .stub td { padding: 7px 8px; font-size: 11.5px; font-weight: bold; border-top: 1px solid #e4e7f0; }
        .foot { margin-top: 26px; border-top: 1px solid #e4e7f0; padding-top: 10px; font-size: 9.5px; color: #9aa0bd; line-height: 1.6; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td class="brand" style="width:60%">{{ $settings->name }}<small>Fee Challan / Payment Voucher</small></td>
            <td class="doc">
                <div class="t">FEE CHALLAN</div>
                <div class="m">Challan #: <b>{{ $challan->challan_no }}</b></div>
                <div class="m">Issued: <b>{{ Format::date($challan->created_at) }}</b></div>
            </td>
        </tr>
    </table>
    <div class="rule" style="margin-top:8px"></div>

    <table class="kv">
        <tr>
            <td class="k">Student ID</td><td class="v">{{ $student?->student_code }}</td>
            <td class="k">Admission #</td><td class="v">{{ $adm?->reg_no }}</td>
        </tr>
        <tr>
            <td class="k">Student Name</td><td class="v">{{ $student?->name }}</td>
            <td class="k">Guardian</td><td class="v">{{ $student?->guardian_name }}</td>
        </tr>
        <tr>
            <td class="k">CNIC / B-Form</td><td class="v">{{ $student?->cnic }}</td>
            <td class="k">Enrolled by</td><td class="v">{{ $adm?->enroller?->name }}</td>
        </tr>
        <tr>
            <td class="k">Course</td>
            <td class="v" colspan="3">{{ $course?->title }} ({{ $course?->code }}) · {{ $course?->trainer?->name }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead><tr><th>Description</th><th class="amt">Amount</th></tr></thead>
        <tbody>
            <tr><td>Course fee, {{ $course?->code }}</td><td class="amt">{{ Format::money($challan->base_amount) }}</td></tr>
            @if ($challan->discount_amount > 0)
                <tr><td>Discount, {{ $challan->discount_reason }}</td><td class="amt">− {{ Format::money($challan->discount_amount) }}</td></tr>
            @endif
            @if ($challan->plan === 'split' && $challan->installments->count())
                @foreach ($challan->installments as $i)
                    <tr><td>Installment {{ $i->seq }} due {{ Format::date($i->due_date) }}</td><td class="amt">{{ Format::money($i->amount) }}</td></tr>
                @endforeach
            @endif
            <tr class="net"><td>Net Payable</td><td class="amt">{{ Format::money($challan->net_amount) }}</td></tr>
        </tbody>
    </table>

    <table class="pay">
        <tr>
            <td style="width:62%">
                <div class="bank">
                    <b>Payment instructions</b><br>
                    Bank: <b>{{ $settings->bank }}</b><br>
                    Account #: <b>{{ $settings->account }}</b><br>
                    IBAN: <b>{{ $settings->iban }}</b><br>
                    Due date: <b>{{ Format::date($challan->due_date) }}</b>
                </div>
            </td>
            <td style="text-align:right">
                <span class="stamp {{ $paid ? 'paid' : 'unpaid' }}">{{ $paid ? 'PAID' : 'UNPAID' }}</span>
                @if ($paid)
                    <div style="font-size:11px;color:#6b7192;margin-top:8px">Paid on {{ Format::date($challan->paid_at) }}<br>via {{ $challan->paid_via }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Bank copy / payment stub --}}
    <table class="stub">
        <tr>
            <th>Challan #</th><th>Student ID</th><th>Student</th><th>Course</th><th>Net</th><th>Due</th>
        </tr>
        <tr>
            <td>{{ $challan->challan_no }}</td>
            <td>{{ $student?->student_code }}</td>
            <td>{{ $student?->name }}</td>
            <td>{{ $course?->code }}</td>
            <td>{{ Format::money($challan->net_amount) }}</td>
            <td>{{ Format::date($challan->due_date) }}</td>
        </tr>
    </table>

    <div class="foot">
        This voucher is system-generated. The admission number is read from the record and cannot be altered.
        Verify payments at accounts@bbt.edu.pk · +92 42 000 0000. Generated {{ Format::date(now()) }}.
    </div>
</body>
</html>
