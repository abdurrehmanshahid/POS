<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per completed write operation, keyed by a token the client minted
 * before it submitted.
 *
 * This exists because a row lock cannot tell two requests apart. BUG-08 stopped
 * two officers colliding on one challan; it did nothing about one officer's
 * request arriving twice, and `lockForUpdate()` actually makes that case tidier
 * rather than safer — it serialises the two writes so both pass their own
 * balance check and both commit. A student who handed over Rs 10,000 against a
 * Rs 20,000 fee ended up recorded as having paid the lot, with the challan
 * marked settled and the drawer short by ten thousand rupees that nothing in
 * the system could account for.
 *
 * The token is minted when a form OPENS, not when it is submitted, which is the
 * whole trick: both clicks of a double-click carry the same component snapshot
 * and therefore the same token, while a genuine second payment goes through a
 * freshly opened dialog and gets a new one. The guard cannot swallow a real
 * collection.
 *
 * No result is stored. The service returns `replayed: true` and the caller,
 * which still holds its own form state, renders from that. Keeping this table
 * free of denormalised copies of other tables' data means it can never disagree
 * with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();

            // The client-minted token. UNIQUE is the entire mechanism: the
            // second insert loses, and losing is how a replay is detected.
            $table->string('key', 64)->unique();

            // What was attempted, for example `payment.record`. Forensics only
            // — nothing queries it, which is why there is no index on it. This
            // is the hottest new write path in the application and an index
            // nobody reads is a cost on every collection.
            $table->string('name', 64);

            // Who submitted it. Nullable and nullOnDelete for the same reason
            // audit rows snapshot their actor: the trail should outlive the
            // account, and a deleted user must not take the record with them.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
