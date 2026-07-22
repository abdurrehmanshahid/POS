<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp the institute prefix onto every existing identifier.
 *
 * Three series are rewritten in place: student codes, admission numbers and
 * challan numbers. Rewriting is a deliberate choice with a real cost, and it is
 * worth writing down: identifiers already appear on challans handed to students
 * and in the audit trail's free-text labels, so a document printed yesterday
 * will name `R26-0009` while the database now says `BBT-R26-0009`. The trade
 * accepted here is one clean series everywhere over a permanent split between
 * pre- and post-prefix records.
 *
 * Idempotent: rows already carrying the prefix are skipped, so a re-run (or a
 * deploy that replays migrations) cannot produce `BBT-BBT-R26-0009`.
 *
 * The audit trail's own `subject_label` column is rewritten too, otherwise the
 * activity log would keep pointing at identifiers that no longer resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = (string) config('institute.code_prefix', 'BBT-');

        if ($prefix === '') {
            return;
        }

        // `BBT-` + `CH-2026-1086` is 16 chars today, but the year rolls and the
        // serial grows, and the old widths left almost no headroom: student_code
        // was 12, and `BBT-R26-0009` is exactly 12. Widen first, rename second.
        Schema::table('students', fn (Blueprint $t) => $t->string('student_code', 32)->change());
        Schema::table('admissions', fn (Blueprint $t) => $t->string('reg_no', 32)->change());
        Schema::table('challans', fn (Blueprint $t) => $t->string('challan_no', 40)->change());

        // `||` is SQLite/Postgres; MySQL reads it as logical OR and would write
        // 0 into every identifier column. Local dev is SQLite, production is
        // MySQL on cPanel, so this has to speak both.
        $concat = fn (string $column) => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "CONCAT('{$prefix}', {$column})"
            : "'{$prefix}' || {$column}";

        foreach ([
            ['students', 'student_code'],
            ['admissions', 'reg_no'],
            ['challans', 'challan_no'],
        ] as [$table, $column]) {
            DB::table($table)
                ->whereNotNull($column)
                ->where($column, 'not like', $prefix.'%')
                ->update([$column => DB::raw($concat($column))]);
        }

        // Keep the activity log readable: its labels are free text captured at
        // write time, so they do not follow the foreign key.
        if (Schema::hasTable('audit_logs')) {
            foreach (['R26-', 'T26-', 'ADM-', 'CH-2026-'] as $series) {
                DB::table('audit_logs')
                    ->where('subject_label', 'like', $series.'%')
                    ->update(['subject_label' => DB::raw($concat('subject_label'))]);
            }
        }
    }

    /**
     * Strips the prefix back off. The column widths are left wide on purpose:
     * narrowing them again could truncate data written while the prefix was in
     * force, and a wider column costs nothing.
     */
    public function down(): void
    {
        $prefix = (string) config('institute.code_prefix', 'BBT-');

        if ($prefix === '') {
            return;
        }

        $len = strlen($prefix) + 1;

        foreach ([
            ['students', 'student_code'],
            ['admissions', 'reg_no'],
            ['challans', 'challan_no'],
        ] as [$table, $column]) {
            DB::table($table)
                ->where($column, 'like', $prefix.'%')
                ->update([$column => DB::raw("substr({$column}, {$len})")]);
        }
    }
};
