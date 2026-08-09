<?php

namespace App\Services;

use App\Models\Operation;
use App\Support\OperationResult;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Run a piece of work at most once per token (GAP-08).
 *
 * The problem this solves is not concurrency. `lockForUpdate()` already handles
 * two officers reaching for the same challan, and it handles the double-click
 * case perfectly badly: it serialises the two requests so cleanly that each one
 * passes its own balance check and both commit. A lock enforces order. It
 * cannot know that two requests were meant to be one.
 */
class Operations
{
    /**
     * @param  string  $key  A token minted when the form OPENED, so both halves
     *                       of a double-click carry the same one.
     * @param  string  $name  What is being done, e.g. `payment.record`.
     * @param  Closure  $work  Runs only if this token has not been seen.
     *
     * @throws RuntimeException if the token is empty
     */
    public function once(string $key, string $name, Closure $work): OperationResult
    {
        // An empty token is a mis-wired form, not a request to skip the guard.
        // Failing loudly here means the mistake surfaces the first time a test
        // exercises that screen, rather than quietly leaving one path
        // unprotected for however long it takes someone to notice.
        if (trim($key) === '') {
            throw new RuntimeException("Operation '{$name}' was submitted without an idempotency token.");
        }

        // What gets stored is a server-side derivation, never the client's
        // token verbatim.
        //
        // The token has to travel to the browser and back — that is the only
        // way both halves of a double-click can carry the same one — which
        // means the client chooses it. Storing it raw made a single global
        // namespace out of every operation in the system, and a token already
        // burned by ANY earlier operation would then satisfy this one: a
        // `student.create` marker made `once()` report a payment as already
        // recorded, so no `payments` row, no audit row, and a green "Part
        // payment received · Rs 5,000 · Cash" on screen. Folding the operation
        // name and the actor into the key means a token can only ever replay
        // the exact operation, by the same person, that minted it.
        $marker = hash('sha256', $name.'|'.(auth()->id() ?? 'guest').'|'.$key);

        // WHICH insert clashed is the whole question, and getting it wrong is
        // worse than the bug this class exists to fix.
        //
        // `$work` reaches plenty of unique indexes of its own —
        // `students.student_code`, `students.cnic`, `challans.challan_no`,
        // `admissions.reg_no`, the live-enrolment constraint from BUG-17. An
        // earlier version of this method wrapped the marker and the work in one
        // `try` and reported every one of those as `replayed: true`. A student
        // whose creation had genuinely failed produced "already on file ·
        // duplicate submission ignored" and no student: a hard failure dressed
        // up as a success, which is the one outcome a guard must never produce.
        //
        // Only a clash on OUR marker means somebody got here first.
        $clashedOnTheMarker = false;

        try {
            // The marker and the work share ONE transaction, and the order
            // matters in both directions.
            //
            // Marker first, so a second request racing this one blocks on the
            // unique index until this commits and then loses cleanly, rather
            // than doing the work and discovering the clash afterwards.
            //
            // Same transaction, so a failed operation rolls the marker back
            // with it. Persisting the token on failure would be worse than the
            // bug: a dropped connection would permanently poison that token,
            // and the officer would be locked out of recording a payment that
            // never actually happened, with no way to tell why.
            return DB::transaction(function () use ($marker, $name, $work, &$clashedOnTheMarker) {
                try {
                    Operation::create(['key' => $marker, 'name' => $name, 'user_id' => auth()->id()]);
                } catch (UniqueConstraintViolationException $e) {
                    $clashedOnTheMarker = true;

                    // Rethrown rather than returned, so the transaction unwinds
                    // instead of committing a half-open one.
                    throw $e;
                }

                return new OperationResult($work(), replayed: false);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! $clashedOnTheMarker) {
                // The work's own constraint, not ours. Let it surface as the
                // failure it is.
                throw $e;
            }

            // Someone got here first, and because the marker is the first
            // statement in their transaction, "first" means their work has
            // committed. Nothing to do but say so.
            return new OperationResult(null, replayed: true);
        }
    }
}
