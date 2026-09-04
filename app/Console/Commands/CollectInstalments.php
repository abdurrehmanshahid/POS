<?php

namespace App\Console\Commands;

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Book money already collected against invoices that already exist.
 *
 * ---------------------------------------------------------------------------
 * Why this is not `roll:import`.
 * ---------------------------------------------------------------------------
 *
 * The importer's job is to create people, enrolments and invoices from a sheet.
 * This is the other half of the same problem, and the August intake is what
 * made it necessary: five of that sheet's eighteen lines are not enrolments at
 * all. They are the SECOND INSTALMENT on a Super Kid Camp cohort enrolled in
 * June, whose five invoices are already on the box, each with exactly Rs 10,000
 * outstanding and each matching a line of the sheet by name and by figure.
 *
 * Run through the importer those lines would have created five duplicate
 * children, banked Rs 50,000 a second time, and left the June balances standing
 * as debts the institute had in fact already been paid. What they need is a
 * payment against an invoice that exists, which is this.
 *
 * ---------------------------------------------------------------------------
 * Why not `ChallanActions::recordPayment()`, the counter's own path.
 * ---------------------------------------------------------------------------
 *
 * Because it requires a payment method from `config('institute.payment_methods')`
 * and refuses anything else, correctly: an officer at the counter knows whether
 * they were handed cash or a transfer slip, and must say. Nobody knows that
 * here. The sheet records an amount and nothing about how it arrived.
 *
 * So this writes 'Unrecorded' — the same value `RollPersister` uses for all 471
 * payments already imported on the production box, and the term
 * `Reporting::byPaymentMethod()` already prints for a payment with no method.
 * It stays outside the counter's list on purpose, so historical money is never
 * mistaken for a collection somebody actually witnessed.
 *
 * Everything else the counter does, this does: the challan is locked, the
 * balance is checked against it, the status flips when nothing is left, and the
 * audit trail records who booked it and when.
 */
class CollectInstalments extends Command
{
    protected $signature = 'roll:collect
        {file : Lines of "<student code> <course code> <amount>", # comments allowed}
        {--commit : Actually write. Without this the command only reports.}
        {--on= : The date the money was collected (YYYY-MM-DD). Required.}
        {--officer= : Username of the officer to record as having taken it. Required.}';

    protected $description = 'Record money already collected against invoices that already exist';

    /** @see RollPersister::METHOD — the same word, for the same reason. */
    private const METHOD = 'Unrecorded';

    public function handle(): int
    {
        try {
            $on = $this->date();
            $officer = $this->officer();
            $lines = $this->read((string) $this->argument('file'));
            $plan = $this->plan($lines);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($plan, $on, $officer);

        if (! $this->option('commit')) {
            $this->newLine();
            $this->components->warn('Dry run. Nothing was written. Re-run with --commit to collect.');

            return self::SUCCESS;
        }

        $booked = 0;
        $failed = [];

        foreach ($plan as $item) {
            if ($item['skip']) {
                continue;
            }

            try {
                // One transaction per payment, matching the importer: a bad
                // fifth line must not roll back four good collections.
                DB::transaction(fn () => $this->collect($item, $on, $officer));
                $booked++;
            } catch (Throwable $e) {
                $failed[] = $item['code'].': '.$e->getMessage();
            }
        }

        $this->newLine();
        $this->components->info($booked.' payment(s) recorded.');

        foreach ($failed as $f) {
            $this->components->error($f);
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function date(): string
    {
        $on = trim((string) $this->option('on'));

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || strtotime($on) === false) {
            throw new RuntimeException(
                '--on is required and must be the date the money was collected, as YYYY-MM-DD. '
                .'Money booked on the wrong day lands in the wrong month of every report.'
            );
        }

        return $on;
    }

    private function officer(): User
    {
        $username = trim((string) $this->option('officer'));

        if ($username === '') {
            throw new RuntimeException('--officer is required: somebody took this money.');
        }

        return User::where('username', $username)->first()
            ?? throw new RuntimeException("No account with the username \"{$username}\".");
    }

    /**
     * @return list<array{line:int, student:string, course:string, amount:int}>
     */
    private function read(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $lines = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $no => $text) {
            [$content] = array_pad(explode('#', $text, 2), 2, '');
            $parts = preg_split('/\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($parts === []) {
                continue;
            }

            if (count($parts) !== 3 || ! ctype_digit($parts[2])) {
                throw new RuntimeException(
                    basename($path)." line {$no}: expected \"<student code> <course code> <amount>\", "
                    .'got "'.trim($content).'"'
                );
            }

            $lines[] = [
                'line' => $no + 1,
                'student' => $parts[0],
                'course' => $parts[1],
                'amount' => (int) $parts[2],
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('Nothing to collect: the file names no payments.');
        }

        return $lines;
    }

    /**
     * Resolve every line to an invoice, and prove the money before writing any.
     *
     * The whole file is resolved first so the dry run can be trusted: a report
     * that said "5 payments" and then failed on the third would be worse than
     * no report. Anything unresolvable is fatal here rather than skipped,
     * because a student code that names nobody means the file and the box have
     * drifted, and the rest of the file cannot be relied on either.
     *
     * @param  list<array{line:int, student:string, course:string, amount:int}>  $lines
     */
    private function plan(array $lines): array
    {
        $plan = [];

        foreach ($lines as $l) {
            $student = Student::where('student_code', $l['student'])->first()
                ?? throw new RuntimeException("Line {$l['line']}: no student with the code \"{$l['student']}\".");

            $admission = Admission::query()
                ->where('student_id', $student->id)
                ->where('status', '!=', 'cancelled')
                ->whereHas('course', fn ($q) => $q->where('code', $l['course']))
                ->with('challan.payments')
                ->first()
                ?? throw new RuntimeException(
                    "Line {$l['line']}: {$student->name} ({$l['student']}) has no live enrolment on {$l['course']}."
                );

            $challan = $admission->challan
                ?? throw new RuntimeException(
                    "Line {$l['line']}: {$student->name}'s {$l['course']} enrolment has no invoice to collect against."
                );

            $paid = (int) $challan->payments->sum('amount');
            $outstanding = (int) $challan->net_amount - $paid;

            // Already done. Recognised rather than refused, so the command can
            // be re-run after a partial failure without booking anything twice
            // — the same property `import_key` gives the importer.
            $already = $challan->payments->firstWhere('note', $this->note());

            if ($already) {
                $plan[] = $this->item($l, $student, $challan, $paid, $outstanding, true,
                    'already collected on this invoice');

                continue;
            }

            if ($challan->status === 'paid' || $outstanding <= 0) {
                throw new RuntimeException(
                    "Line {$l['line']}: {$student->name}'s {$l['course']} invoice is already settled. "
                    .'Booking more would bank money the invoice does not owe.'
                );
            }

            if ($l['amount'] > $outstanding) {
                throw new RuntimeException(
                    "Line {$l['line']}: {$student->name} owes {$outstanding} on {$l['course']}, "
                    ."but the file collects {$l['amount']}."
                );
            }

            $plan[] = $this->item($l, $student, $challan, $paid, $outstanding, false, '');
        }

        return $plan;
    }

    private function item(array $l, Student $s, Challan $c, int $paid, int $out, bool $skip, string $why): array
    {
        return [
            'code' => $s->student_code,
            'name' => $s->name,
            'course' => $l['course'],
            'challan' => $c,
            'challan_no' => $c->challan_no,
            'amount' => $l['amount'],
            'paid' => $paid,
            'outstanding' => $out,
            'skip' => $skip,
            'why' => $why,
        ];
    }

    /**
     * The note that identifies these payments, and makes a re-run safe.
     *
     * Carries the date, so two instalments collected on two different days
     * against one invoice are two payments rather than the second being
     * mistaken for a repeat of the first.
     */
    private function note(): string
    {
        return 'Collected '.$this->option('on').', recorded from the institute\'s intake sheet';
    }

    private function collect(array $item, string $on, User $officer): void
    {
        /** @var Challan $challan */
        $challan = Challan::query()->lockForUpdate()->findOrFail($item['challan']->getKey());

        // Re-read inside the lock. The figures in the plan were taken outside
        // it, and between then and now the counter may have taken money on the
        // same invoice — the exact race `ChallanActions::recordPayment()` locks
        // against, and the reason this is not simply trusted from the report.
        $paid = (int) $challan->payments()->sum('amount');
        $outstanding = (int) $challan->net_amount - $paid;

        if ($outstanding < $item['amount']) {
            throw new RuntimeException(
                "outstanding changed to {$outstanding} while this was running; nothing booked"
            );
        }

        Payment::create([
            'challan_id' => $challan->id,
            'amount' => $item['amount'],
            'method' => self::METHOD,
            'received_by' => $officer->id,
            'received_at' => $on,
            'note' => $this->note(),
        ]);

        if ($outstanding - $item['amount'] > 0) {
            return;
        }

        $challan->update([
            'status' => 'paid',
            'paid_via' => self::METHOD,
            'paid_at' => $on,
        ]);

        Audit::markedPaid($challan, $officer, self::METHOD, Carbon::parse($on));
    }

    private function report(array $plan, string $on, User $officer): void
    {
        $this->components->info(
            'Collecting on '.$on.', recorded as taken by '.$officer->name.' ('.$officer->username.').'
        );

        $this->newLine();
        $this->table(
            ['Student', 'Name', 'Course', 'Invoice', 'Owed', 'Collecting', 'Left', ''],
            array_map(fn ($i) => [
                $i['code'],
                $i['name'],
                $i['course'],
                $i['challan_no'],
                $i['outstanding'],
                $i['skip'] ? '—' : $i['amount'],
                $i['skip'] ? '—' : $i['outstanding'] - $i['amount'],
                $i['why'],
            ], $plan)
        );

        $due = array_filter($plan, fn ($i) => ! $i['skip']);

        $this->line('  Collecting <fg=green>'.array_sum(array_column($due, 'amount')).'</> across '
            .count($due).' invoice(s)'
            .(count($due) === count($plan) ? '.' : ', '.(count($plan) - count($due)).' already done.'));
    }
}
