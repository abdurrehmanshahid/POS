<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (spec §10). Not part of the authoritative ERD; generated
 * from real events (enrolment, payment, overdue, capacity) and filtered by the
 * viewer's permissions/scope. `is_revenue` hides from users without revenue.view;
 * `is_admin` hides from users without scope.all; `student_id` hides from users
 * who cannot see that student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // overdue, enrol, payment, capacity, system
            $table->string('title', 160);
            $table->string('sub', 200)->nullable();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('challan_id')->nullable()->constrained('challans')->nullOnDelete();
            $table->boolean('is_revenue')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
