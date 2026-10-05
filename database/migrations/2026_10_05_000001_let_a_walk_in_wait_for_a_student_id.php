<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A walk-in is recorded before they have taken a course.
 *
 * "Add student" used to allocate the next R26/T26 code to anybody who came to
 * the counter and asked about a course. Most of them never enrolled, so the
 * series filled with numbers that belonged to nobody the institute teaches, and
 * a series (Regular or Track) had to be picked before anyone knew which course
 * they would take.
 *
 * `kind` gains a third value:
 *
 *   walkin  recorded at the counter, not registered on a course. They carry a
 *           code from their own series (W26-####), not counted as a student.
 *
 * RegistrationService swaps the W code for an R or T one when they register.
 * A walk-in cannot be billed any other way, so a W code never appears on a
 * challan or receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->enum('kind', ['student', 'contact', 'walkin'])->default('student')->change();
        });
    }

    public function down(): void
    {
        // Refuse rather than silently promote: dropping the value would count
        // every walk-in as a registered student.
        $walkIns = DB::table('students')->where('kind', 'walkin')->count();

        if ($walkIns > 0) {
            throw new RuntimeException(
                "There are {$walkIns} walk-ins on file. Register them or remove them first."
            );
        }

        Schema::table('students', function (Blueprint $table) {
            $table->enum('kind', ['student', 'contact'])->default('student')->change();
        });
    }
};
