<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named permission sets (spec §2.2). Roles are fully editable data, access is
 * derived from permissions, never hardcoded to a role name. `id` is a stable
 * string key: `admin`, `officer`, or a generated `role_<slug>_<3digits>`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 80)->unique();
            $table->boolean('is_system')->default(false);
            $table->enum('tone', ['navy', 'orange'])->default('navy');
            // Two-factor is a property of the ROLE, not of a hardcoded role name,
            // so tightening security later is a data change rather than a deploy.
            // Seeded true for Administrator; officers sign in with a password
            // alone until you flip this from the role editor.
            $table->boolean('requires_2fa')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
