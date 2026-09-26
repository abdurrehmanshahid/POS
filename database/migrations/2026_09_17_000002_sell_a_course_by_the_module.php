<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A course may be sold whole or one module at a time.
 *
 * The catalogue priced a course with a single `courses.fee`, so a student who
 * only wanted the first third of a syllabus had to be billed the whole thing
 * and given an ad-hoc discount to bring it back down. That put the real price
 * in `discount_reason` as free text, where no report can read it, and made
 * "what did this student actually buy?" unanswerable.
 *
 * Two tables:
 *
 *   `course_modules`     the catalogue side — what a course is divided into
 *                        and what each part costs.
 *   `admission_modules`  the sale side — which of those parts one enrolment
 *                        actually bought, and what it was charged for them.
 *
 * ── Why the sale snapshots the price ──────────────────────────────────────
 *
 * `admission_modules.billed_amount` copies the module's fee at the moment of
 * sale rather than reading `course_modules.fee` back later. The institute
 * re-prices its catalogue between intakes; without the snapshot, raising
 * Module 1 from 45,000 to 50,000 would silently rewrite every voucher ever
 * printed, and a challan that no longer adds up to its own line items is one
 * the office cannot reconcile against the money it took. `admissions.
 * billed_amount` already snapshots the whole-course fee for exactly this
 * reason; this is the same rule one level down.
 *
 * ── Why a course with no modules is untouched ─────────────────────────────
 *
 * Modules are opt-in per course. 41 courses exist and all of them are priced
 * whole, so a course with no `course_modules` rows keeps billing `courses.fee`
 * exactly as it does today. Nothing needs backfilling and no existing
 * registration changes shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            // Display and billing order — "Module 1" before "Module 2". Not the
            // id, because modules get inserted out of order and reordered, and
            // a voucher listing them by insertion order reads as a mistake.
            $table->unsignedSmallInteger('seq');

            $table->string('title', 120);
            $table->unsignedInteger('fee');

            // Retiring a module must not erase it from the enrolments that
            // bought it, so this is a flag rather than a delete. An inactive
            // module cannot be sold; it still prints on the vouchers that
            // already carry it.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Indexed for ordering, NOT unique on (course_id, seq), and that
            // is a deliberate choice rather than an omission.
            //
            // A retired or removed module keeps its position, because the
            // vouchers that already list it order themselves by `seq` and
            // moving it would reshuffle documents the institute has already
            // handed out. So the positions of the live modules and the dead
            // ones share one number line, and uniqueness across it would make
            // retiring "Module 2" permanently block the next module from
            // occupying slot 2 — an error the officer cannot act on and did
            // not cause.
            //
            // Nothing is lost by dropping it: `syncModules()` is the only
            // writer and always assigns 1..n over the form's rows, so two live
            // modules cannot claim one slot in the first place.
            $table->index(['course_id', 'seq']);
        });

        Schema::create('admission_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete, not cascade: a module that has been sold cannot
            // be hard-deleted out from under the enrolment that bought it. The
            // catalogue's own route out is `is_active`, above.
            $table->foreignId('course_module_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('billed_amount');
            $table->timestamps();

            // One enrolment cannot buy the same module twice. Unlike the live
            // enrolment rule lifted in 2026_09_17_000001, this one is about a
            // single row's internal consistency rather than a policy about
            // repeat study: two identical lines on one admission would double
            // the fee it contributes to the invoice with nothing to show for it.
            $table->unique(['admission_id', 'course_module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_modules');
        Schema::dropIfExists('course_modules');
    }
};
