<?php

namespace App\Services;

use App\Models\Counter;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * System-generated, atomic serial allocation (spec §7.3 / §7.4).
 *
 * Counters store the NEXT value to assign (use-then-increment). Each allocation
 * locks the counter row inside a transaction, so concurrent registrations
 * serialise and never collide; UNIQUE indexes are the final backstop. Increment
 * only commits with the surrounding write.
 */
class Sequences
{
    /**
     * The institute stamp on every generated identifier, e.g. `BBT-`.
     *
     * Public because the wizard previews an ID before it is allocated and the
     * seeder writes historical ones directly; all three must produce byte-for-
     * byte the same string or a preview will not match what gets assigned.
     */
    public static function prefix(): string
    {
        return (string) config('institute.code_prefix', '');
    }

    /** BBT-R26-0011 / BBT-T26-0003, 4-digit, independent per type (spec §7.3). */
    public function nextStudentCode(string $type): string
    {
        $value = $this->bump('student:'.$type);

        return self::studentCode($type, $value);
    }

    /** Format a student code from its parts, without touching the counter. */
    public static function studentCode(string $type, int $value): string
    {
        return sprintf('%s%s26-%04d', self::prefix(), $type === 'T' ? 'T' : 'R', $value);
    }

    /** Format an admission number from its serial. */
    public static function admissionNo(int $value): string
    {
        return sprintf('%sADM-%04d', self::prefix(), $value);
    }

    /**
     * The code that WOULD be assigned next, without consuming it.
     *
     * Used for the "ID to assign" preview in the wizard and the add-student
     * form (spec §7.3). Read-only on purpose: previewing must never advance the
     * counter, or abandoning a half-filled form would burn student numbers and
     * leave permanent gaps in the series.
     *
     * Being a peek, it is inherently advisory, two officers previewing at once
     * see the same number and one of them will get the next. The authoritative
     * allocation is {@see nextStudentCode()}, under a row lock.
     */
    public function peekStudentCode(string $type): string
    {
        $type = $type === 'T' ? 'T' : 'R';
        $value = Counter::where('key', 'student:'.$type)->value('value') ?? 1;

        return self::studentCode($type, $value);
    }

    /** BBT-ADM-0012, global sequence (spec §7.4). */
    public function nextAdmissionNo(): string
    {
        return self::admissionNo($this->bump('admission'));
    }

    /** The admission number that WOULD be assigned next, without consuming it. */
    public function peekAdmissionNo(): string
    {
        return self::admissionNo((int) (Counter::where('key', 'admission')->value('value') ?? 1));
    }

    /**
     * BBT-CH-2026-1086, plain integer, no zero-pad. The serial lives in
     * settings.next_challan_serial (the atomic counter that "cannot be reset by
     * hand", spec §2.12 / §7.4).
     */
    public function nextChallanNo(): string
    {
        return DB::transaction(function () {
            $setting = Setting::query()->lockForUpdate()->firstOrFail();
            $serial = $setting->next_challan_serial;
            $setting->update(['next_challan_serial' => $serial + 1]);

            return self::challanNo($serial);
        });
    }

    /** Format a challan number from its serial. */
    public static function challanNo(int $serial): string
    {
        return self::prefix().'CH-2026-'.$serial;
    }

    /**
     * BBT-RC-000042, the receipt number for a collected payment.
     *
     * DERIVED from the payment's own id rather than allocated from a counter,
     * and that is the whole design. A receipt is not a new record — it is a
     * rendering of a `payments` row that already exists — so it needs no
     * sequence of its own, no extra column, and no write at print time.
     *
     * The consequence that matters: printing a receipt twice produces the same
     * number both times, and reprinting one from three years ago still produces
     * the number the student is holding. A counter would have handed out a
     * fresh number on every reprint, so two pieces of paper describing one
     * payment would disagree, which is exactly what a receipt exists to prevent.
     *
     * `payments.id` is auto-increment and never reused, so this inherits
     * uniqueness rather than restating it.
     */
    public static function receiptNo(int $paymentId): string
    {
        return sprintf('%sRC-%06d', self::prefix(), $paymentId);
    }

    /** Atomically read-and-advance a named counter; returns the value used. */
    private function bump(string $key): int
    {
        return DB::transaction(function () use ($key) {
            $counter = Counter::query()->where('key', $key)->lockForUpdate()->first()
                ?? Counter::create(['key' => $key, 'value' => 1]);

            $used = $counter->value;
            $counter->update(['value' => $used + 1]);

            return $used;
        });
    }
}
