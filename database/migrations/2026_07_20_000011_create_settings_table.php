<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single institute config row (spec §2.12). next_challan_serial mirrors the
 * atomic challan counter and "cannot be reset by hand". Changes to bank details
 * and serials are recorded in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('bank', 120);
            $table->string('account', 60);
            $table->string('iban', 60);
            $table->unsignedInteger('next_challan_serial')->default(1);
            $table->boolean('twofa_required')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
