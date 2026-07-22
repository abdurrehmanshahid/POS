<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1–2 installments per challan (spec §2.9). Split creation UI is roadmap; the
 * table is modelled now. For split: inst1 = round(net/2), inst2 = net − inst1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challan_id')->constrained('challans')->cascadeOnDelete();
            $table->unsignedTinyInteger('seq'); // 1 or 2
            $table->unsignedInteger('amount');
            $table->date('due_date');
            $table->enum('status', ['unpaid', 'paid'])->default('unpaid');
            $table->dateTime('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
