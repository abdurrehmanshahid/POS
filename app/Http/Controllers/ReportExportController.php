<?php

namespace App\Http\Controllers;

use App\Services\Reporting;
use App\Support\Download;
use App\Support\Format;
use App\Support\Period;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports to a real multi-sheet .xlsx.
 *
 * The prototype's Export button raised a toast and downloaded nothing, which is
 * worse than having no button: it tells the user their report is on its way.
 *
 * Money is written as a NUMBER with a currency format, never as the string
 * "Rs 20,000". A formatted string cannot be summed, sorted or charted, so an
 * accountant's first action after opening the file would be to strip it back
 * out again.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, Reporting $reporting): StreamedResponse
    {
        $user = $request->user();

        // Server-side gate: the same permission that hides the money blocks in
        // the UI must also refuse the file.
        abort_unless($user->can('reports.view'), 403);

        $period = Period::resolve(
            (string) $request->query('period', 'month'),
            $request->query('from'),
            $request->query('to'),
        );

        $book = new Spreadsheet;
        $book->getProperties()
            ->setCreator(config('institute.name', 'Big Binary Tech Institute'))
            ->setTitle('Institute report '.$period->rangeLabel());

        $canSeeMoney = $user->can('revenue.view');

        $this->summarySheet($book, $reporting, $user, $period, $canSeeMoney);

        if ($canSeeMoney) {
            $this->collectionsSheet($book, $reporting, $user, $period);
            $this->coursesSheet($book, $reporting, $user, $period);
        }

        $this->duesSheet($book, $reporting, $user);

        if ($canSeeMoney && $user->can('scope.all')) {
            $this->officersSheet($book, $reporting, $period);
        }

        $book->setActiveSheetIndex(0);
        $filename = 'BBT Report '.$period->from->format('Y-m-d').' to '.$period->to->format('Y-m-d').'.xlsx';

        // Named through the helper: this filename carries SPACES, which is
        // exactly the case an unquoted disposition cannot express, so it is the
        // most likely of all of them to arrive as an unnamed blob.
        return Download::named(
            response()->streamDownload(function () use ($book) {
                (new Xlsx($book))->save('php://output');
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache',
            ]),
            $filename,
        );
    }

    // ---- Sheets --------------------------------------------------------------

    private function summarySheet(Spreadsheet $book, Reporting $r, $user, Period $period, bool $money): void
    {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Summary');

        $sheet->setCellValue('A1', config('institute.name', 'Big Binary Tech Institute'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Report period: '.$period->label().' ('.$period->rangeLabel().')');
        $sheet->setCellValue('A3', 'Generated: '.now()->format('d M Y H:i'));
        $sheet->setCellValue('A4', 'Scope: '.($user->can('scope.all') ? 'All registrations' : 'Own enrolments only'));

        if (! $money) {
            $sheet->setCellValue('A6', 'Money figures are not included: this account does not hold the revenue permission.');
            $sheet->getColumnDimension('A')->setWidth(70);

            return;
        }

        $s = $r->summary($user, $period);

        $rows = [
            ['Metric', 'Value'],
            ['Collected in period', $s['collected']],
            ['Payments received', $s['payments']],
            ['Average per day', $s['per_day']],
            ['Collected today', $s['today']],
            ['Billed in period', $s['billed']],
            ['Challans issued', $s['issued_count']],
            ['Days in period', $s['days']],
        ];

        $this->writeTable($sheet, $rows, 6, [1 => 'money'], [2, 3, 6, 7]);
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(18);
    }

    private function collectionsSheet(Spreadsheet $book, Reporting $r, $user, Period $period): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Daily collections');

        $rows = [['Date', 'Period', 'Collected']];
        foreach ($r->collectionSeries($user, $period) as $b) {
            $rows[] = [$b->date, $b->label.' '.$b->sub, $b->total];
        }
        $this->writeTable($sheet, $rows, 1, [2 => 'money']);

        $start = count($rows) + 3;
        $sheet->setCellValue('A'.$start, 'By payment method');
        $sheet->getStyle('A'.$start)->getFont()->setBold(true);

        $methodRows = [['Method', 'Payments', 'Total']];
        foreach ($r->byPaymentMethod($user, $period) as $m) {
            $methodRows[] = [$m->method, $m->count, $m->total];
        }
        $this->writeTable($sheet, $methodRows, $start + 1, [2 => 'money'], [1]);

        foreach (['A' => 14, 'B' => 16, 'C' => 16] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
    }

    private function coursesSheet(Spreadsheet $book, Reporting $r, $user, Period $period): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Revenue by course');

        $rows = [['Code', 'Course', 'Enrolments', 'Collected']];
        foreach ($r->revenueByCourse($user, $period, 100) as $c) {
            $rows[] = [$c->code, $c->title, $c->enrolments, $c->total];
        }
        $this->writeTable($sheet, $rows, 1, [3 => 'money'], [2]);

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(13);
        $sheet->getColumnDimension('D')->setWidth(16);
    }

    private function duesSheet(Spreadsheet $book, Reporting $r, $user): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Outstanding dues');

        $dues = $r->duesAgeing($user);

        $ageing = [['Ageing bucket', 'Challans', 'Amount']];
        foreach ($dues['buckets'] as $b) {
            $ageing[] = [$b['label'], $b['count'], $b['total']];
        }
        $this->writeTable($sheet, $ageing, 1, [2 => 'money'], [1]);

        $start = count($ageing) + 3;
        $sheet->setCellValue('A'.$start, 'By student');
        $sheet->getStyle('A'.$start)->getFont()->setBold(true);

        $rows = [['Student ID', 'Student', 'Courses', 'Days overdue', 'Owes']];
        foreach ($dues['students'] as $s) {
            $rows[] = [$s->code, $s->name, $s->courses, $s->days_late, $s->amount];
        }
        $this->writeTable($sheet, $rows, $start + 1, [4 => 'money'], [3]);

        foreach (['A' => 14, 'B' => 26, 'C' => 40, 'D' => 14, 'E' => 16] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
    }

    private function officersSheet(Spreadsheet $book, Reporting $r, Period $period): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Officer performance');

        $rows = [['Officer', 'Username', 'Enrolments', 'Billed', 'Collected', 'Outstanding', 'Collection %', 'Avg discount']];
        foreach ($r->officerPerformance($period) as $o) {
            $rows[] = [
                $o->name, $o->username, $o->enrolments,
                $o->billed, $o->received, $o->outstanding,
                $o->collection_rate, $o->avg_discount,
            ];
        }
        $this->writeTable($sheet, $rows, 1, [3 => 'money', 4 => 'money', 5 => 'money', 7 => 'money'], [2, 6]);

        foreach (['A' => 24, 'B' => 16, 'C' => 13, 'D' => 15, 'E' => 15, 'F' => 15, 'G' => 14, 'H' => 15] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
    }

    // ---- Helpers ---------------------------------------------------------------

    /**
     * Write a header row plus data, styling the header and applying number
     * formats by zero-based column index.
     *
     * @param  array<int, array<int, mixed>>  $rows  first row is the header
     * @param  array<int, string>  $moneyCols  zero-based index => 'money'
     * @param  array<int, int>  $intCols  zero-based indexes formatted as integers
     */
    private function writeTable($sheet, array $rows, int $startRow, array $moneyCols = [], array $intCols = []): void
    {
        if ($rows === []) {
            return;
        }

        $sheet->fromArray($rows, null, 'A'.$startRow, true);

        $lastCol = chr(64 + count($rows[0]));
        $headerRange = 'A'.$startRow.':'.$lastCol.$startRow;

        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('EEF0F7');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $dataStart = $startRow + 1;
        $dataEnd = $startRow + count($rows) - 1;

        if ($dataEnd < $dataStart) {
            return;
        }

        // Whole rupees, thousands-separated. Stored as numbers so the sheet can
        // total and chart them (spec §0: integer PKR, no decimals anywhere).
        foreach (array_keys($moneyCols) as $i) {
            $col = chr(65 + $i);
            $sheet->getStyle($col.$dataStart.':'.$col.$dataEnd)
                ->getNumberFormat()->setFormatCode('#,##0');
        }

        foreach ($intCols as $i) {
            $col = chr(65 + $i);
            $sheet->getStyle($col.$dataStart.':'.$col.$dataEnd)
                ->getNumberFormat()->setFormatCode('0');
        }
    }
}
