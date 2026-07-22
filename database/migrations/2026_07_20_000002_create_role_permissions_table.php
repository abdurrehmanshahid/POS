<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * role → permission_key (spec §2.3). One of the 15 keys in §3. can($key) is
 * simply "the current user's role has that permission_key".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role_id', 40);
            $table->string('permission_key', 60);
            $table->timestamps();

            $table->unique(['role_id', 'permission_key']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
