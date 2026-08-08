<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical students arrive without a CNIC or a guardian name.
 *
 * Both columns were NOT NULL because every student the application itself
 * creates comes through the registration wizard or the student form, and both
 * of those demand a valid CNIC before they will submit. That assumption held
 * for as long as the only way in was the counter.
 *
 * The institute's existing roll is exported from their previous system and
 * carries neither field: name, course, batch, phone and money, nothing else.
 * Rather than mint placeholder CNICs to satisfy the column, which would put
 * fabricated national identity numbers into a system that uses CNIC to
 * recognise a returning student, the column now permits null and records the
 * honest fact that the number is not known.
 *
 * The UNIQUE index is deliberately kept. MySQL and SQLite both exclude NULLs
 * from uniqueness, so any number of students may have no CNIC while any CNIC
 * that IS recorded still cannot be used twice. This is the same property the
 * live-enrolment constraint relies on.
 *
 * Entry through the UI is unchanged: StudentService::clean() and the wizard
 * still require a well-formed CNIC and a guardian name, so this relaxes the
 * import path only, not the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('cnic', 15)->nullable()->change();
            $table->string('guardian_name', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversing this requires every student to have a CNIC again. Rows
        // imported without one would violate the column, so fill them before
        // rolling back rather than having the migration invent values.
        Schema::table('students', function (Blueprint $table) {
            $table->string('cnic', 15)->nullable(false)->change();
            $table->string('guardian_name', 120)->nullable(false)->change();
        });
    }
};
