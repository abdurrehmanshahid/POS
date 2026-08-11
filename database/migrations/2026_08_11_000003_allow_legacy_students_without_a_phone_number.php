<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `students.phone` becomes nullable, for the same reason `cnic` did.
 *
 * 72 rows of the institute's roll carry "-" or nothing in the phone column.
 * These are real students with real money against them, recorded years before
 * this system existed. The choice was to invent a number, drop the students, or
 * record that the number is not known; the institute chose the third, which is
 * the only one of the three that is true.
 *
 * A blank is stored as NULL and never as '', so "we do not have a number" has
 * exactly one representation. `Contact::optional()` already enforces that rule
 * for the guardian and the CNIC and is reused here rather than restated.
 *
 * ---------------------------------------------------------------------------
 * What this costs, stated plainly
 * ---------------------------------------------------------------------------
 *
 * The phone is the only channel a fee reminder travels down. A student with no
 * number cannot be chased for a balance, so these 72 are unreachable until
 * somebody fills the number in — which is exactly why they are worth importing
 * rather than dropping: a debt you can see is one you can act on, and a student
 * left out of the system entirely is one nobody will ever collect from.
 *
 * The registration wizard still REQUIRES a phone. This relaxes the column for
 * history that already exists; it does not relax what the counter asks for
 * today. A new student without a number is a mistake, an old one is a fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversing this while a phoneless student exists would fail on the NOT
        // NULL constraint, and there is no number to put back. A placeholder is
        // written instead so the rollback completes, and it is deliberately
        // recognisable rather than a plausible-looking fake number.
        DB::table('students')->whereNull('phone')->update(['phone' => 'UNKNOWN']);

        Schema::table('students', function (Blueprint $table) {
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
