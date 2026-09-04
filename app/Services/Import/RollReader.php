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
     * The institute's own hand-kept intake sheet.
     *
     * A second layout, not a second importer. While the POS was offline the
     * institute went on enrolling and recorded it by hand, so the August intake
     * exists only as this shape — there is no export of it to fall back on, and
     * refusing to read it would mean the month never enters the system.
     *
     * It differs from the export in three ways that matter, each handled below:
     *
     *   - It carries columns the export never had: CNIC, and a DURATION that is
     *     part of which course was actually sold ({@see courseText()}).
     *   - It omits columns the importer requires: Status, CSR and Registration
     *     Date. The first is genuinely absent and read as such; the other two
     *     have to be supplied on the command line, and their absence is an
     *     error naming the flag rather than 18 rows rejected one by one.
     *   - Its Pending Fee column is not maintained ({@see intakeMoney()}).
     *
     * The header spellings are the sheet's own, typo included ("Piad"). Fixing
     * it here would be a fix to something we do not control and would stop the
     * file being readable the day somebody corrects it upstream, so both
     * spellings are accepted.
     */
    private const INTAKE_HEADERS = [
        'name' => 'NAME',
        'course' => 'COURSE NAME',
        'duration' => 'DURATION',
        'phone' => 'Contact No.',
        'cnic' => 'CNIC NO.',
        'original_price' => 'TOTAL FEE',
        'discounted_price' => 'DISCOUNTED FEE',
        'advance' => '1st Installment',
        'stated_balance' => 'Pending Fee',
        'second_paid' => '2nd installment Piad',
    ];

    /** Header spellings accepted as equivalent, lowercased. */
    private const SPELLINGS = [
        '2nd installment piad' => '2nd installment paid',
    ];

    /**
     * Lines carrying something but not a person, in the order they appear.
     *
     * A wholly blank line is padding and says nothing. A line with a serial
     * number and no student, or a figure sitting under the table with no row
     * beside it, is different: somebody typed it, and the previous behaviour —
     * skip anything without a name — discarded it without ever saying so. The
     * August sheet has three, two of them carrying Rs 20,000 each.
     *
     * @var list<array{line:int, content:string}>
     */
    public array $ignored = [];

    /**
     * @param  array{csr?:string, registered_on?:string}  $defaults  values for columns
     *                                                               this layout does not have
     * @return list<RollRow>
     *
     * @throws RuntimeException when a required column is missing
     */
    public function read(string $path, array $defaults = []): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $sheet = IOFactory::load($path)->getActiveSheet();
        $grid = $sheet->toArray(null, true, false, false);

        if ($grid === []) {
            throw new RuntimeException('The sheet is empty.');
        }

        $this->ignored = [];

        [$index, $layout] = $this->layout(array_shift($grid));

        // Refused here, once, rather than by every row in turn. Without a CSR
        // the resolver rejects all 18 lines with "no CSR named", which is 18
        // true statements that together say the wrong thing: the sheet is fine,
        // the command was called without something only a person can supply.
        foreach (['csr' => '--csr="Ali Raza"', 'registered_on' => '--registered-on=2026-08-01'] as $key => $flag) {
            if (! array_key_exists($key, $index) && ($defaults[$key] ?? '') === '') {
                throw new RuntimeException(
                    "This sheet has no {$this->label($key)} column, so one has to be given for the "
                    ."whole file. Pass {$flag}."
                );
            }
        }

        $rows = [];
        foreach ($grid as $offset => $cells) {
            // +2: one for the header that was shifted off, one because
            // spreadsheets are 1-indexed. The number printed in a rejection has
            // to be the number the operator sees in Excel.
            $line = $offset + 2;

            $get = fn (string $key): string => array_key_exists($key, $index)
                ? trim((string) ($cells[$index[$key]] ?? ''))
                : '';

            // A wholly blank line is the export's trailing padding, not a
            // student, and reporting 40 of them as rejections buries the real
            // ones. A line with something else on it is recorded instead of
            // being dropped in silence.
            if ($get('name') === '' && $get('course') === '' && $get('phone') === '') {
                $this->note($line, $cells);

                continue;
            }

            $discounted = $this->money($get('discounted_price'));

            // The two layouts disagree about what their money columns mean, so
            // the three derived figures are settled before the row is built
            // rather than corrected afterwards. RollRow is readonly by design —
            // a stage that could rewrite the money it was handed is exactly what
            // the rejection report has to be able to trust.
            [$received, $balance, $second] = $layout === 'intake'
                ? $this->intakeMoney($get, $discounted)
                : [
                    $this->money($get('total_received')),
                    $this->money($get('balance')),
                    $this->money($get('second_instalment')),
                ];

            $row = new RollRow(
                line: $line,
                name: $this->collapse($get('name')),
                courseText: $this->courseText($get('course'), $get('duration')),
                status: $get('status'),
                csr: $this->collapse($get('csr')) ?: ($defaults['csr'] ?? ''),
                phone: $this->phone($get('phone')),
                cnic: $this->cnic($get('cnic')),
                batchText: $this->collapse($get('batch')),
                registeredOn: $this->date($get('registered_on')) ?? ($defaults['registered_on'] ?? null),
                secondDueOn: $this->date($get('second_due_on')),
                originalPrice: $this->money($get('original_price')),
                discountedPrice: $discounted,
                advance: $this->money($get('advance')),
                secondInstalment: $second,
                balance: $balance,
                totalReceived: $received,
            );

            if ($layout === 'intake' && ($stated = $get('stated_balance')) !== ''
                && $this->money($stated) !== $balance) {
                $row->warn(
                    "the sheet says {$this->money($stated)} pending, but fee {$discounted} less "
                    ."{$received} collected leaves {$balance}; the sheet's figure was not used"
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Which of the two shapes this file is, and where its columns are.
     *
     * The export is tried first and the intake sheet second, so a file that is
     * both — which none is — is read as the one with the stronger data. A file
     * that is neither is refused naming BOTH sets of missing columns, because
     * "missing Status, CSR, Registration Date" on its own sends somebody to add
     * three columns to a sheet that was never meant to have them.
     *
     * @param  list<string|null>  $header
     * @return array{array<string, int>, string}
     */
    private function layout(array $header): array
    {
        $seen = [];
        foreach ($header as $i => $label) {
            $key = mb_strtolower($this->collapse((string) $label));
            $seen[self::SPELLINGS[$key] ?? $key] = $i;
        }

        $missing = [];

        foreach (['export' => self::HEADERS, 'intake' => self::INTAKE_HEADERS] as $name => $wanted) {
            $index = [];
            $absent = [];

            foreach ($wanted as $key => $label) {
                $lookup = mb_strtolower($label);
                $lookup = self::SPELLINGS[$lookup] ?? $lookup;

                if (array_key_exists($lookup, $seen)) {
                    $index[$key] = $seen[$lookup];
                } else {
                    $absent[] = $label;
                }
            }

            if ($absent === []) {
                return [$index, $name];
            }

            $missing[$name] = $absent;
        }

        throw new RuntimeException(
            'The sheet matches neither known layout. '
            .'As an export it is missing: '.implode(', ', $missing['export']).'. '
            .'As an intake sheet it is missing: '.implode(', ', $missing['intake']).'. '
            .'Found: '.implode(', ', array_filter(array_keys($seen))).'.'
        );
    }

    /** The column's name in the export, for an error about the sheet that lacks it. */
    private function label(string $key): string
    {
        return self::HEADERS[$key] ?? $key;
    }

    /**
     * The intake sheet's money, which is shaped differently from the export's.
     *
     * TWO instalment columns, both of them amounts already collected — "1st
     * Installment" and "2nd installment Piad" — so what was received is their
     * sum. The export instead has one Total Amount that already means money in
     * hand, and a Second Installment column that means the opposite on a
     * pending row. Reading the intake sheet through the export's rule would
     * have thrown away every second payment in the file.
     *
     * THE BALANCE IS DERIVED, not read. The sheet has a "Pending Fee" column
     * and it is not maintained: six Kids Camp rows state 0 pending against a
     * fee of 20,000 with 10,000 collected, and one of those cells contains a
     * broken self-referencing formula (`=-J5`) rather than a number anybody
     * typed. The institute has confirmed the column is wrong and the money
     * genuinely outstanding.
     *
     * So the check `validateMoney()` normally performs — refuse any row whose
     * own Balance disagrees with fee minus received — cannot be performed on
     * this layout, because there is no trustworthy balance to check against.
     * What replaces it is a WARNING naming both numbers on every row where they
     * differ. The row imports on the derived figure, and `--warnings=` lists
     * exactly which rows were overridden and by how much, so an override is
     * visible per row rather than being a property of the file that somebody
     * has to remember. Deriving silently would have been the same import with
     * no record that 55,500 of debt was created against the sheet's own word.
     *
     * `secondInstalment` is set to that balance, which is the export's own
     * convention for a pending row: the amount scheduled and not yet paid.
     * Nothing is scheduled here regardless — the sheet names no due date, so
     * `RollPersister::schedule()` leaves the balance unscheduled.
     *
     * @return array{int, int, int} received, balance, second instalment
     */
    private function intakeMoney(callable $get, int $discounted): array
    {
        $received = $this->money($get('advance')) + $this->money($get('second_paid'));

        // Clamped at zero so a row that collected MORE than the fee is refused
        // by validateMoney()'s "collected X against a fee of Y", which names the
        // real fault, rather than by "negative money", which does not.
        $balance = max(0, $discounted - $received);

        return [$received, $balance, $balance];
    }

    /**
     * What the row bought, with its length where the sheet records one.
     *
     * The intake sheet sells the same course at more than one length — "Digital
     * Media Marketing" for one month at 20,000 and for three at 60,000 — and
     * the name alone cannot tell them apart. Joined into the course text so the
     * pair resolves through `config/roll-import.php` like every other value,
     * where the mapping is reviewable, rather than through a rule in here that
     * nobody would think to look at.
     *
     * Appended to each comma-separated part rather than to the whole string.
     * The resolver splits on commas, so "Shopify, DevOps" plus "2-Months" has
     * to become two three-month courses and not a course called
     * "DevOps (2 Months)" beside a bare "Shopify".
     *
     * The hyphen is normalised away because the sheet writes one duration two
     * ways — "2-Months" on one line and "2 Months" on the next — and those are
     * the same length written twice, not two products. Nothing else about the
     * text is touched: this is the whitespace normalisation `collapse()` already
     * does, not a guess at what a course means.
     */
    private function courseText(string $course, string $duration): string
    {
        $course = $this->collapse($course);
        $duration = trim((string) preg_replace('/[\s\-]+/u', ' ', $duration));

        if ($course === '' || $duration === '') {
            return $course;
        }

        $parts = array_filter(array_map('trim', explode(',', $course)), fn ($p) => $p !== '');

        return implode(', ', array_map(fn ($p) => "{$p} ({$duration})", $parts));
    }

    /**
     * Remember a line that carried something but not a person.
     *
     * The content is kept as the operator would see it — "J: 20000" — because
     * the point is to be able to find the cell again. Without the value the
     * note says only that a line was ignored, which is not enough to act on.
     */
    private function note(int $line, array $cells): void
    {
        $content = [];

        foreach ($cells as $i => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $content[] = $this->columnLetter($i).': '.$value;
            }
        }

        if ($content !== []) {
            $this->ignored[] = ['line' => $line, 'content' => implode(', ', $content)];
        }
    }

    private function columnLetter(int $i): string
    {
        $letter = '';

        for ($n = $i; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letter = chr(65 + $n % 26).$letter;
        }

        return $letter;
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
     *
     * A number listed in `roll-import.phone_corrections` is replaced by the
     * form it should have been written in. That table exists for numbers the
     * normaliser cannot rescue on its own and a person has identified — the
     * intake sheet drops the leading symbol from every number it holds, which
     * `Contact::normalizePhone()` recovers for a Pakistani mobile and cannot
     * for a foreign one, because "16785495919" is only a US number if somebody
     * knows that it is. Corrections are keyed on the digits alone, so the same
     * entry covers the number however it was spaced.
     */
    private function phone(string $v): string
    {
        $v = $this->collapse($v);

        if ($v === '-') {
            return '';
        }

        $digits = (string) preg_replace('/\D+/', '', $v);
        $corrections = config('roll-import.phone_corrections', []);

        return $digits !== '' && isset($corrections[$digits]) ? $corrections[$digits] : $v;
    }

    /**
     * The national identity number, where the sheet carries one.
     *
     * Kept as written apart from whitespace. The institute writes it
     * `35202-7872126-1` and that hyphenation is how it is printed on the card
     * and how every clerk will search for it; normalising to bare digits would
     * make the record correct and unfindable.
     */
    private function cnic(string $v): string
    {
        $v = $this->collapse($v);

        return $v === '-' ? '' : $v;
    }
}
