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
    /** R26-0011 / T26-0003, 4-digit, independent per type (spec §7.3). */
    public function nextStudentCode(string $type): string
    {
        $value = $this->bump('student:'.$type);

        return sprintf('%s26-%04d', $type, $value);
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

        return sprintf('%s26-%04d', $type, $value);
    }

    /** ADM-0012, global sequence (spec §7.4). */
    public function nextAdmissionNo(): string
    {
        return sprintf('ADM-%04d', $this->bump('admission'));
    }

    /**
     * CH-2026-1086, plain integer, no zero-pad. The serial lives in
     * settings.next_challan_serial (the atomic counter that "cannot be reset by
     * hand", spec §2.12 / §7.4).
     */
    public function nextChallanNo(): string
    {
        return DB::transaction(function () {
            $setting = Setting::query()->lockForUpdate()->firstOrFail();
            $serial = $setting->next_challan_serial;
            $setting->update(['next_challan_serial' => $serial + 1]);

            return 'CH-2026-'.$serial;
        });
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
