<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Stage 1 and 2: read the sheet, and normalise every cell into a typed row.
 *
 * Nothing here touches the database or resolves anything. It turns a
 * spreadsheet into `RollRow` objects and stops, so the stages that follow can
 * be tested against hand-built rows without an .xlsx anywhere near them.
 */
class RollReader
{
    /**
     * Column letters, keyed by the meaning the importer uses.
     *
     * Read from the header row rather than hardcoded positions, because the
     * institute's export tool has already produced two files with different
     * column counts and a positional reader would silently import the wrong
     * numbers rather than fail.
     */
    private const HEADERS = [
        'name' => 'Name',
        'course' => 'Course',
        'status' => 'Status',
        'csr' => 'CSR',
        'phone' => 'Phone',
        'batch' => 'Batch',
        'registered_on' => 'Registration Date',
        'second_due_on' => 'Pending Payment Due Date',
        'original_price' => 'Original Price',
        'discounted_price' => 'Discounted Price',
        'advance' => 'Advance Payment',
        'second_instalment' => 'Second Installment',
        'balance' => 'Balance',
        'total_received' => 'Total Amount',
    ];

    /**
     * @return list<RollRow>
     *
     * @throws RuntimeException when a required column is missing
     */
    public function read(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $sheet = IOFactory::load($path)->getActiveSheet();
        $grid = $sheet->toArray(null, true, false, false);

        if ($grid === []) {
            throw new RuntimeException('The sheet is empty.');
        }

        $index = $this->columnIndex(array_shift($grid));

        $rows = [];
        foreach ($grid as $offset => $cells) {
            // +2: one for the header that was shifted off, one because
            // spreadsheets are 1-indexed. The number printed in a rejection has
            // to be the number the operator sees in Excel.
            $line = $offset + 2;

            $get = fn (string $key): string => trim((string) ($cells[$index[$key]] ?? ''));

            // A wholly blank line is the export's trailing padding, not a
            // student, and reporting 40 of them as rejections buries the real
            // ones.
            if ($get('name') === '' && $get('course') === '' && $get('phone') === '') {
                continue;
            }

            $rows[] = new RollRow(
                line: $line,
                name: $this->collapse($get('name')),
                courseText: $this->collapse($get('course')),
                status: $get('status'),
                csr: $this->collapse($get('csr')),
                phone: $this->phone($get('phone')),
                batchText: $this->collapse($get('batch')),
                registeredOn: $this->date($get('registered_on')),
                secondDueOn: $this->date($get('second_due_on')),
                originalPrice: $this->money($get('original_price')),
                discountedPrice: $this->money($get('discounted_price')),
                advance: $this->money($get('advance')),
                secondInstalment: $this->money($get('second_instalment')),
                balance: $this->money($get('balance')),
                totalReceived: $this->money($get('total_received')),
            );
        }

        return $rows;
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function columnIndex(array $header): array
    {
        $seen = [];
        foreach ($header as $i => $label) {
            $seen[mb_strtolower($this->collapse((string) $label))] = $i;
        }

        $index = [];
        $missing = [];
        foreach (self::HEADERS as $key => $label) {
            $lookup = mb_strtolower($label);
            if (! array_key_exists($lookup, $seen)) {
                $missing[] = $label;

                continue;
            }
            $index[$key] = $seen[$lookup];
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'The sheet is missing these columns: '.implode(', ', $missing).'. '
                .'Found: '.implode(', ', array_filter(array_keys($seen))).'.'
            );
        }

        return $index;
    }

    /** Collapse runs of whitespace, so a stray double space is not a new value. */
    private function collapse(string $v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $v));
    }

    /**
     * Integer PKR, matching the rest of the application.
     *
     * Rounds rather than truncates: the roll carries at least one price of
     * 54,996 against 55,000 collected, and a truncating cast would turn a
     * rounding artefact into a four-rupee discrepancy the validator then
     * rejects the row for.
     */
    private function money(string $v): int
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $v);

        return $clean === '' || $clean === '-' ? 0 : (int) round((float) $clean);
    }

    /**
     * Null for anything that is not a date, including the literal "-" the roll
     * uses for "not set". Deciding what a missing date means is the validator's
     * job, not the reader's.
     *
     * A real date cell in an .xlsx is a NUMBER — days since 1900 — not text, and
     * `toArray()` is called with `formatData: false` so it arrives that way.
     * `strtotime('45505')` is false, so without the serial branch a file whose
     * dates are genuinely typed as dates would reject every single row for
     * having no registration date. The institute's current export happens to
     * write them as strings, which is the only reason this was not immediately
     * obvious, and is not a property to depend on.
     *
     * Slash-separated dates are refused rather than guessed. `strtotime` reads
     * `03/07/2025` as 7 March; a Pakistani export means 3 July. Guessing puts
     * an enrolment and all of its backdated money in the wrong month and says
     * nothing, and only dates with a day above 12 would ever look wrong.
     */
    private function date(string $v): ?string
    {
        if ($v === '' || $v === '-') {
            return null;
        }

        if (is_numeric($v)) {
            return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }

        if (str_contains($v, '/')) {
            return null;
        }

        $ts = strtotime($v);

        return $ts === false ? null : date('Y-m-d', $ts);
    }

    /**
     * The roll writes "-" for "no number on file", which is not a phone.
     * Returned as an empty string so the validator can say so plainly.
     */
    private function phone(string $v): string
    {
        $v = $this->collapse($v);

        return $v === '-' ? '' : $v;
    }
}
