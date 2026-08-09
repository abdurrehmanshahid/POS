<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stable identity for an enrolment that came from an imported roll.
 *
 * Importing the institute's existing students is not a one-shot event. The
 * spreadsheet gets corrected and re-exported, the dry-run is read, aliases are
 * added, and the file is run again — and every one of those runs has to be able
 * to tell "this enrolment is already here" from "this is a new one". Without
 * that, the second run bills 478 students a second time.
 *
 * On `admissions` rather than `students`, because one spreadsheet line can name
 * several courses and therefore produce several enrolments, and the thing that
 * must not be duplicated is the enrolment with its invoice and its money.
 *
 * UNIQUE, so the guarantee holds even if two operators run the importer at the
 * same moment. The service checks first for a readable message; the index is
 * what makes the check true rather than merely likely — the same pairing used
 * for duplicate live enrolments in 2026_08_03_000002.
 *
 * Nullable: every admission created at the counter has no import key, and NULLs
 * are excluded from uniqueness on both MySQL and SQLite, so any number of them
 * coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->string('import_key', 64)->nullable()->after('billed_amount');
            $table->unique('import_key');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropUnique(['import_key']);
            $table->dropColumn('import_key');
        });
    }
};
