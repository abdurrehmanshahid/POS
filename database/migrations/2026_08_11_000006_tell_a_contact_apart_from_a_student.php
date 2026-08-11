<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Not everyone the institute bills is a student.
 *
 * The roll contains 32 lines, Rs 289,950, for people who never enrolled on
 * anything: a co-working desk rented by the month, a certificate reissued, a
 * recovery batch settled. `challans` was widened to bill them (see
 * 2026_08_11_000005), but the person on the other end of that invoice still had
 * to be a row in `students`, and once they are there every count, roster and
 * report treats them as a student the institute is teaching.
 *
 * That is not a cosmetic problem. "How many students do we have" is the first
 * number on the owner's console and the one quoted to the board; inflating it
 * with room tenants makes it a different number from the one the trainers see.
 *
 * `kind` separates the two:
 *
 *   student  someone who is or has been enrolled. Everything as before.
 *   contact  someone who has only ever bought a non-course service.
 *
 * A contact is NOT a lesser record. They have a code, an invoice, a receipt and
 * a place in the ledger; they are simply not counted as a student, because they
 * are not one. And the door between the two only opens one way: a contact who
 * enrols becomes a student on the spot (RegistrationService::register), which is
 * why there is no "convert" button anywhere — the enrolment IS the conversion,
 * and a second, manual step could only ever be forgotten.
 *
 * Deliberately a new column rather than a fourth value in `type`. `type` is
 * R|T, Regular or Track, and it says which fee structure a student is on — a
 * contact has a `type` too, whatever they were registered under. Overloading it
 * would make `type = 'C'` mean "no fee structure applies", and every existing
 * `where('type', ...)` would have to learn about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Default 'student', so every row that exists now — and every
            // caller that does not yet know this column is here — keeps meaning
            // exactly what it meant before. The importer's charge path is the
            // only thing that writes 'contact'.
            $table->enum('kind', ['student', 'contact'])
                ->default('student')
                ->after('type');

            // Every list of people filters on this, so it earns an index.
            $table->index('kind');
        });

        // Explicit, rather than trusting the DEFAULT to have applied. On MySQL
        // an ALTER with a DEFAULT does backfill; the guarantee is worth one
        // cheap statement rather than a footnote about engines.
        DB::table('students')->whereNull('kind')->update(['kind' => 'student']);
    }

    public function down(): void
    {
        // Refuse rather than silently promote. Dropping this column turns every
        // room tenant into a student in the headline count, quietly, with no
        // way to tell them apart again afterwards — the exact confusion the
        // column was added to end.
        $contacts = DB::table('students')->where('kind', 'contact')->whereNull('deleted_at')->count();

        if ($contacts > 0) {
            throw new RuntimeException(
                "There are {$contacts} contacts on file. Dropping `kind` would count them as students "
                .'in every report with no way to separate them again. Enrol them or remove them first.'
            );
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
