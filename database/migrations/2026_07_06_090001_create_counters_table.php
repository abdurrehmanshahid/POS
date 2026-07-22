<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backing store for concurrency-safe sequence assignment (e.g. registration numbers).
 * A row is locked with lockForUpdate() inside a transaction so two simultaneous
 * registrations can never draw the same number. See App\Services\RegistrationNumberGenerator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();       // e.g. "registration:2026"
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counters');
    }
};
