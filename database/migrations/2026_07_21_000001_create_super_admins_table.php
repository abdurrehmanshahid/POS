<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform owner's account, deliberately NOT a row in `users`.
 *
 * Why a separate table and a separate auth guard rather than another role?
 *
 *   The institute Administrator holds `staff.manage`, which by design lets them
 *   create users, edit roles and grant permissions. If the super admin lived in
 *   `users`, every one of those screens would need a special case to hide and
 *   protect that row, and a single missed `where` would expose or, worse,
 *   allow tampering with the account that can delete everything. Isolation by
 *   table means the staff screens simply cannot reach it: their queries run
 *   against `users`, and the super admin is not there.
 *
 * The two guards share the session cookie but not the identity; Laravel keeps
 * `auth()->guard('web')` and `auth()->guard('superadmin')` independent, which is
 * also what makes impersonation safe, the super admin can hold a `web` identity
 * for a target user while their own `superadmin` session stays intact underneath.
 *
 * There is no `role_id` here. The super admin is not permission-scoped; the
 * whole point of the account is that it is unconditional. Restraint comes from
 * mandatory TOTP and from the fact that every action lands in `audit_logs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('username', 60)->unique();
            $table->string('email', 160)->unique();
            $table->string('password');

            // ---- TOTP, mandatory (never nullable in practice) ----------------
            // Encrypted at rest via the model's 'encrypted' cast. Unlike staff,
            // a super admin with no confirmed second factor is forced into
            // enrolment before they can reach any panel screen.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // Replay guard, see the note on `users`. Matters far more here,
            // because every destructive action demands a fresh code.
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_admins');
    }
};
