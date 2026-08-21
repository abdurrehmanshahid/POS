<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cohorts, the thing the legacy invoice calls "Batch # 11".
 *
 * A cohort is one run of one course: the same syllabus taught to a group who
 * start together. That is why course_id lives here rather than a pivot table.
 * "Shopify" is the product; "Shopify, Batch # 11, starting 18 June" is the
 * thing a student is actually enrolled in, and the thing a trainer teaches.
 *
 * Membership is carried by the admission, not by the student, because the same
 * person can sit in Batch # 11 for Shopify and Batch # 3 for Web Development at
 * the same time. A cohort_id on students would force one of those to be a lie.
 *
 * Exactly one cohort per course may be `is_open` at a time. That is the one new
 * enrolments join automatically, which is what makes assignment a consequence of
 * registering rather than a second thing to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohorts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);                    // "Batch # 11"
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();  // null = uncapped
            // The intake currently accepting students. Enforced to one per course
            // by the service, not by a partial index, because MySQL 8 and SQLite
            // disagree on the syntax and the rule is a business rule anyway.
            $table->boolean('is_open')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_id', 'name']);
            $table->index(['course_id', 'is_open']);
        });

        Schema::table('admissions', function (Blueprint $table) {
            // Nullable: every admission that predates cohorts has no batch, and
            // inventing one for them would be fabricating history.
            $table->foreignId('cohort_id')->nullable()->after('course_id')
                ->constrained()->nullOnDelete();
            $table->index('cohort_id');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            // The constraint first, then the column it points through: MySQL
            // refuses to drop a column still carrying a foreign key.
            //
            // Was `dropConstrainedForeignKey('cohort_id')`, which Laravel 13
            // removed — and which left the column behind even when it existed,
            // so a rollback followed by a re-migrate died on "column cohort_id
            // already exists". Nobody had ever rolled this back to find out.
            $table->dropForeign(['cohort_id']);

            // The index has to go before the column on SQLite, which validates
            // surviving indexes after a column drop and fails the whole
            // statement with "no such column: cohort_id". MySQL would tidy it up
            // on its own, but doing it explicitly keeps one rollback path that
            // works on both engines rather than one that only works in
            // production.
            $table->dropIndex(['cohort_id']);
            $table->dropColumn('cohort_id');
        });
        Schema::dropIfExists('cohorts');
    }
};
