<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Students are records, not logins (spec §2.4). Created once, reused across
 * enrolments. `joined` display = created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_code', 12)->unique(); // R26-0009 / T26-0002
            $table->enum('type', ['R', 'T']);             // R = Regular, T = Track
            $table->string('name', 120);
            $table->string('guardian_name', 120);
            $table->string('cnic', 15)->unique();          // #####-#######-#
            $table->string('phone', 20);                   // +92 3XX XXXXXXX
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            // Removing a student hides the record but keeps their admissions,
            // challans and audit trail intact and restorable (spec §7.7 ethos).
            // Irreversible destruction is the separate, TOTP-gated purge.
            $table->softDeletes();

            $table->index('created_by');
            $table->index('name');
            // Search on the directory screen filters these three (spec §9.11).
            $table->index('cnic');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
