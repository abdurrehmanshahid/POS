<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('username', 60)->unique();
            $table->string('email', 160)->unique();
            $table->string('phone', 20)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // Thin permission layer (spec §2.1): role is a string key into `roles`.
            $table->string('role_id', 40)->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_reset_password')->default(true);

            // ---- TOTP two-factor (RFC 6238) ---------------------------------
            // Secret and recovery codes are stored ENCRYPTED (Laravel 'encrypted'
            // cast, AES-256-GCM under APP_KEY), a leaked DB dump must not hand
            // an attacker working second factors. `two_factor_confirmed_at` is
            // null until the user proves they can generate a valid code, so a
            // half-finished enrolment can never lock anyone out.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // Replay guard: the last 30-second timestep this account consumed.
            // A TOTP code stays valid for its whole window, so without this the
            // same six digits could authorise several destructive actions.
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable();

            // ---- Forensics ---------------------------------------------------
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('deactivated_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            // Staff are soft-deleted so their audit trail and the admissions
            // they signed stay intact; a purge is a separate, TOTP-gated act.
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
