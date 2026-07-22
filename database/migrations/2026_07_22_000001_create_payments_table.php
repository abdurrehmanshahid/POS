<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collections against a challan, one row per handover of money.
 *
 * Until now a challan was paid or it was not, which could not express the thing
 * the institute actually does every day: take 40,000 today and 40,000 next
 * month. `challans.status` stays as a denormalised flag so existing queries and
 * the status pill keep working, but the authoritative figure for what has been
 * collected is Σ payments.amount, and the balance is derived from it.
 *
 * Rows are append-only by intent. Correcting a collection means recording the
 * offsetting movement, not editing history, for the same reason the audit trail
 * is immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');            // integer PKR, like every other money column
            $table->string('method', 32);                 // config('institute.payment_methods')
            // Nullable only so the backfill below can run: rows written by the
            // application always name the officer who took the money.
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('challan_id');
            $table->index('received_at');
        });

        // A challan already flagged paid means its full net was collected. Without
        // this, Ledger::received() would read zero the moment it starts summing
        // payments, and every historical figure in the system would vanish.
        DB::table('challans')
            ->where('status', 'paid')
            ->orderBy('id')
            ->each(function ($challan) {
                DB::table('payments')->insert([
                    'challan_id' => $challan->id,
                    'amount' => $challan->net_amount,
                    'method' => $challan->paid_via ?: 'Cash',
                    'received_by' => null,
                    'received_at' => $challan->paid_at ?: $challan->created_at,
                    'note' => 'Reconstructed from the paid flag when per-payment records were introduced.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
