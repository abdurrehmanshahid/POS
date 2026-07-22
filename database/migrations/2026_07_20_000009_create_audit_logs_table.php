<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only activity trail (spec §2.10, generalised).
 *
 * Originally this table only recorded money changes on a challan. It is now the
 * single source of truth for EVERY consequential act in the system, sign-ins,
 * failed attempts, payments, discounts, cancellations, role edits, password
 * resets, deletions, purges and impersonation, because a super admin who can
 * delete a person must not be able to do so unobserved.
 *
 * Two deliberate design choices, both about surviving deletion:
 *
 * 1. NO FOREIGN KEYS on `challan_id`, `actor_id` or the polymorphic subject.
 *    An append-only ledger must outlive the rows it describes. A real FK would
 *    either block the delete or cascade the evidence away with it, exactly the
 *    wrong outcome when the thing being recorded IS the deletion.
 *
 * 2. Actor and subject are SNAPSHOTTED as text (`actor_name`, `subject_label`).
 *    Once a staff account is purged there is nothing left to join against, so
 *    the trail keeps its own copy of who did it and what they did it to.
 *
 * Challan-drawer parity (spec §2.10) is unaffected: that timeline is simply
 * `where challan_id = ?`, and challan events still fill field/old/new exactly
 * as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // ---- Money trail (spec §2.10) -----------------------------------
            // Nullable: only challan events fill this.
            $table->unsignedBigInteger('challan_id')->nullable();

            // ---- Who acted ---------------------------------------------------
            // Polymorphic because a super admin is NOT a row in `users`, they
            // authenticate on a separate guard, so a plain integer FK to users
            // could not represent them. 'system' covers scheduled jobs.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 20)->default('user'); // user|superadmin|system
            $table->string('actor_name', 120)->nullable();     // snapshot, survives purge

            // ---- What was acted upon ------------------------------------------
            $table->string('subject_type', 60)->nullable();    // e.g. App\Models\Student
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 160)->nullable();  // snapshot, survives purge

            // ---- What happened -------------------------------------------------
            $table->string('action', 60);
            // Nullable: field/old/new describe a field-level change, which only
            // some events have, a sign-in has no "old value".
            $table->string('field', 40)->nullable();
            $table->string('old_value', 120)->nullable();
            $table->string('new_value', 120)->nullable();
            // Free-form extras: purged-record snapshots, permission diffs,
            // impersonation targets. Keeps the fixed columns honest.
            $table->json('context')->nullable();

            // ---- Request forensics ----------------------------------------------
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('challan_id');
            $table->index(['actor_type', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
