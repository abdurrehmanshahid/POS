<?php

namespace App\Http\Controllers;

use App\Services\ReportBook;
use App\Support\Csv;
use App\Support\Download;
use App\Support\InstituteWideViewer;
use App\Support\Period;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * The report, as a file, in whichever of three shapes the reader can open.
 *
 * The prototype's Export button raised a toast and downloaded nothing, which is
 * worse than having no button: it tells the user their report is on its way.
 *
 * Three formats, because the report is five sections and CSV is one table:
 *
 *   xlsx  one workbook, five sheets, money formatted and column widths set.
 *         The richest of the three and still the default, because it is what
 *         the reports screen has shipped and what people already have saved.
 *   csv   one file, the sections stacked and labelled. Opens on a double click
 *         anywhere, including on a machine with no Excel at all.
 *   zip   one .csv per section. The only shape that keeps each section a clean
 *         rectangle, which is what a pivot table or an import job needs.
 *
 * None of them queries anything. {@see ReportBook} builds the tables once and
 * all three write out the same description, so the three files cannot disagree
 * with each other or with the screen. Adding a fourth format is a writer, not
 * another set of queries.
 *
 * Money is written as a NUMBER — 20000, never "Rs 20,000" and never "20,000".
 * A formatted string cannot be summed, sorted or charted, so an accountant's
 * first action after opening the file would be to strip it back out again. In
 * the .xlsx a number format makes it read as 20,000 without it ceasing to be a
 * number; in the CSVs a thousands separator would be a field separator, so the
 * bare integer is the only correct answer there anyway.
 */
class ReportExportController extends Controller
{
    /**
     * `format` accepts only these. Anything else falls back to the first rather
     * than 400s, matching `Period::resolve`, which quietly returns 'month' for
     * nonsense: a mistyped query string should still hand back a report.
     */
    private const FORMATS = ['xlsx', 'csv', 'zip'];

    public function __invoke(Request $request, ReportBook $book): StreamedResponse
    {
        // The super admin's copy of this route sits behind `auth:superadmin`,
        // which is its gate. The super admin is not a staff User and is not
        // permission-scoped, so it exports the whole institute.
        $user = $request->routeIs('superadmin.*')
            ? new InstituteWideViewer
            : $request->user();

        // Server-side gate: the same permission that hides the money blocks in
        // the UI must also refuse the file.
        abort_unless($user->can('reports.view'), 403);

        $period = Period::resolve(
            (string) $request->query('period', 'month'),
            $request->query('from'),
            $request->query('to'),
        );

        $format = (string) $request->query('format', 'xlsx');
        if (! in_array($format, self::FORMATS, true)) {
            $format = self::FORMATS[0];
        }

        // Built BEFORE the response so a query that throws produces a 500 the
        // error handler can render, rather than an exception raised halfway
        // through a stream the browser has already begun saving as a file.
        $sections = $book->build($user, $period);

        $stem = 'BBT Report '.$period->from->format('Y-m-d').' to '.$period->to->format('Y-m-d');

        return match ($format) {
            'csv' => $this->csv($sections, $stem.'.csv'),
            'zip' => $this->zip($sections, $stem.'.zip'),
            default => $this->xlsx($sections, $stem.'.xlsx', $period),
        };
    }

    // ---- Writers -------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function xlsx(array $sections, string $filename, Period $period): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator(config('institute.name', 'Big Binary Tech Institute'))
            ->setTitle('Institute report '.$period->rangeLabel());

        foreach ($sections as $i => $section) {
            // Sheet 0 already exists; every later one has to be created. Doing
            // this the other way round leaves an empty "Worksheet" at the front.
            $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $this->writeSheet($sheet, $section);
        }

        $spreadsheet->setActiveSheetIndex(0);

        // Named through the helper: this filename carries SPACES, which is
        // exactly the case an unquoted disposition cannot express, so it is the
        // most likely of all of them to arrive as an unnamed blob.
        return Download::named(
            response()->streamDownload(function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache',
            ]),
            $filename,
        );
    }

    /**
     * Every section stacked into one sheet, each behind its own title row.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private function csv(array $sections, string $filename): StreamedResponse
    {
        return Download::named(
            response()->streamDownload(function () use ($sections) {
                echo Csv::BOM;

                foreach ($sections as $i => $section) {
                    // Two blank rows between sections. Excel's "format as table"
                    // and pandas' `read_csv` both treat a blank line as a break,
                    // so this is what makes the stack separable again later.
                    if ($i > 0) {
                        echo "\r\n\r\n";
                    }

                    echo Csv::row([$section['title']]);
                    echo $this->sectionBody($section);
                }
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache',
            ]),
            $filename,
        );
    }

    /**
     * One .csv per section, so each stays a clean rectangle.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private function zip(array $sections, string $filename): StreamedResponse
    {
        return Download::named(
            response()->streamDownload(function () use ($sections) {
                // ZipArchive writes to a path, never to a stream, so the archive
                // is assembled on disk and then handed out. It is small — five
                // text files — and the temp file is removed on the way out even
                // if the client disconnects mid-download, which is why unlink
                // sits in `finally` rather than after `readfile`.
                $tmp = tempnam(sys_get_temp_dir(), 'bbt-report-');

                try {
                    $zip = new ZipArchive;
                    $zip->open($tmp, ZipArchive::OVERWRITE);

                    foreach ($sections as $i => $section) {
                        // Numbered because a zip listing sorts by name: without
                        // the prefix "Summary" lands between "Revenue by course"
                        // and the rest, and the reading order of the report is
                        // lost for the sake of the alphabet.
                        $entry = sprintf('%02d %s.csv', $i + 1, $section['title']);

                        $zip->addFromString($entry, Csv::BOM.$this->sectionBody($section));
                    }

                    $zip->close();

                    readfile($tmp);
                } finally {
                    @unlink($tmp);
                }
            }, $filename, [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-store, no-cache',
            ]),
            $filename,
        );
    }

    // ---- Rendering -----------------------------------------------------------

    /**
     * A section's preamble, note and tables as CSV records — everything except
     * the section's own title, which the two CSV shapes place differently: the
     * stacked file needs it as a row, the zip carries it in the entry name.
     *
     * @param  array<string, mixed>  $section
     */
    private function sectionBody(array $section): string
    {
        $out = '';

        foreach ($section['preamble'] as $line) {
            $out .= Csv::row([$line]);
        }

        if ($section['preamble'] !== []) {
            $out .= "\r\n";
        }

        if ($section['note'] !== null) {
            $out .= Csv::row([$section['note']]);
        }

        foreach ($section['tables'] as $i => $table) {
            if ($i > 0) {
                $out .= "\r\n";
            }

            if ($table['heading'] !== null) {
                $out .= Csv::row([$table['heading']]);
            }

            foreach ($table['rows'] as $row) {
                $out .= Csv::row($row);
            }
        }

        return $out;
    }

    /**
     * One section onto one worksheet, preserving the layout the .xlsx has
     * shipped with: preamble from A1, a blank row, then the tables two rows
     * apart with their headings above them.
     *
     * @param  array<string, mixed>  $section
     */
    private function writeSheet($sheet, array $section): void
    {
        $sheet->setTitle($section['title']);

        foreach ($section['preamble'] as $i => $line) {
            $sheet->setCellValue('A'.($i + 1), $line);
        }

        if ($section['preamble'] !== []) {
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        }

        // A blank row between the preamble and the first table; a sheet with no
        // preamble starts its table at row 1 rather than leaving row 1 empty.
        $row = $section['preamble'] === [] ? 1 : count($section['preamble']) + 2;

        if ($section['note'] !== null) {
            $sheet->setCellValue('A'.$row, $section['note']);
        }

        foreach ($section['tables'] as $i => $table) {
            if ($i > 0) {
                // Two blank rows, then the heading, then the table under it.
                $row += 2;
                $sheet->setCellValue('A'.$row, $table['heading']);
                $sheet->getStyle('A'.$row)->getFont()->setBold(true);
                $row++;
            }

            $this->writeTable($sheet, $table, $row);
            $row += count($table['rows']);
        }

        foreach ($section['widths'] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    /**
     * Write a header row plus data, styling the header and applying number
     * formats by zero-based column index.
     *
     * @param  array<string, mixed>  $table  `rows`, first of which is the header
     */
    private function writeTable($sheet, array $table, int $startRow): void
    {
        $rows = $table['rows'];

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
        foreach ($table['money'] as $i) {
            $col = chr(65 + $i);
            $sheet->getStyle($col.$dataStart.':'.$col.$dataEnd)
                ->getNumberFormat()->setFormatCode('#,##0');
        }

        foreach ($table['ints'] as $i) {
            $col = chr(65 + $i);
            $sheet->getStyle($col.$dataStart.':'.$col.$dataEnd)
                ->getNumberFormat()->setFormatCode('0');
        }
    }
}
