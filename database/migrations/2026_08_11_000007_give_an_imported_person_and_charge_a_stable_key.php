<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make a re-run of the roll import safe for the rows that have no admission.
 *
 * `admissions.import_key` (2026_08_08_000004) is what stops the importer loading
 * the same enrolment twice, and it works because every course row produces an
 * admission to hang the key on. A non-course charge produces none, so those 32
 * rows had nothing to be recognised by: running `roll:import --commit` a second
 * time would have booked Azeem's six co-working months a second time, Rs 90,000
 * of collections that never arrived, with no error and nothing out of place to
 * notice.
 *
 * Two keys, because a charge row carries two separate identities and conflating
 * them was the first mistake here:
 *
 *   students.import_key  WHO. Azeem's six lines are one person, so they must
 *                        find the contact the first line created rather than
 *                        mint six of him.
 *
 *   challans.import_key  WHAT HAPPENED. Each of those six months is a distinct
 *                        booking and each must import exactly once.
 *
 * One key could not do both jobs: strong enough to separate six bookings, it
 * separates six Azeems too.
 *
 * Both are nullable and both stay NULL for everything the counter creates. Only
 * the importer writes them, and only on the charge path — a course row still
 * keys through its admissions, unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('import_key', 64)->nullable()->after('created_by');

            // UNIQUE, matching admissions.import_key, because an index that
            // merely makes the lookup fast leaves the guarantee resting on the
            // importer remembering to look. This is the row that stops a second
            // Azeem existing at all.
            //
            // Many NULLs are fine: every engine this runs on (MySQL, SQLite)
            // treats NULL as distinct in a unique index, which is what lets the
            // 478 course-imported students and everyone the counter registers
            // share the absence of a key.
            $table->unique('import_key');
        });

        Schema::table('challans', function (Blueprint $table) {
            $table->string('import_key', 64)->nullable()->after('description');
            $table->unique('import_key');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['import_key']);
            $table->dropColumn('import_key');
        });

        Schema::table('challans', function (Blueprint $table) {
            $table->dropUnique(['import_key']);
            $table->dropColumn('import_key');
        });
    }
};
