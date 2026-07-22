<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per enrolment: student × course (spec §2.7). `enrolled_by` is the
 * source of truth for who registered whom, never editable after create and the
 * anchor for officer scoping. Cancel is a soft status change, not a delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->string('reg_no', 16)->unique(); // ADM-####
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('course_id')->constrained('courses');
            $table->foreignId('enrolled_by')->constrained('users');
            $table->enum('status', ['validated', 'pending', 'cancelled'])->default('validated');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('student_id');
            $table->index('course_id');
            $table->index('enrolled_by');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
