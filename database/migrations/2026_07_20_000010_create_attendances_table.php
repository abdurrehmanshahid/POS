<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily per-student attendance (spec §2.11). Backs the Reports "Attendance
 * summary" cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses');
            $table->foreignId('student_id')->constrained('students');
            $table->date('session_date');
            $table->enum('status', ['present', 'absent', 'leave']);
            $table->foreignId('marked_by')->constrained('users');

            $table->index(['course_id', 'session_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
