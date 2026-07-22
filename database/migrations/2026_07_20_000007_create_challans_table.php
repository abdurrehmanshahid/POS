<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exactly one challan per admission (spec §2.8). All amounts are whole-rupee
 * integers. net_amount is derived = base_amount − discount_amount and is never
 * accepted from the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challans', function (Blueprint $table) {
            $table->id();
            $table->string('challan_no', 20)->unique(); // CH-2026-####
            $table->foreignId('admission_id')->unique()->constrained('admissions');
            $table->unsignedInteger('base_amount');
            $table->unsignedInteger('discount_amount')->default(0);
            $table->text('discount_reason')->nullable();
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('net_amount'); // derived = base − discount
            $table->enum('plan', ['full', 'split'])->default('full');
            $table->date('due_date');
            $table->enum('status', ['unpaid', 'paid'])->default('unpaid');
            $table->string('paid_via', 20)->nullable(); // Cash, Bank transfer, Card, Wallet, Cheque
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challans');
    }
};
