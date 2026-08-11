<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removal of people and records, in two clearly separated tiers.
 *
 *   REMOVE, a soft delete. The row keeps existing, stops appearing anywhere,
 *             and can be restored intact along with everything hanging off it.
 *             This is what "delete" means in the UI, and it is reversible.
 *
 *   PURGE, a real DELETE. Irreversible, TOTP-gated, and blocked outright
 *             when the record carries financial history that other records
 *             depend on.
 *
 * The rule that shapes everything below: **the audit row is written before the
 * data disappears, inside the same transaction.** A purge that leaves no trace
 * of itself is indistinguishable from a breach, so `context` carries a full
 * snapshot of the destroyed record, enough to reconstruct what was lost and to
 * answer "who removed this student, and when?" a year later.
 *
 * Note that `audit_logs` has deliberately no foreign keys (see its migration),
 * which is what allows the trail to outlive its subject.
 */
class RecordRemoval
{
    // ---- Guards -------------------------------------------------------------

    /**
     * Why this record cannot be purged, or null if it may be.
     *
     * Surfaced in the UI *before* the confirm dialog opens, so the operator
     * learns the answer is "no" without first typing a name and a TOTP code.
     */
    public function purgeBlocker(Model $record): ?string
    {
        if ($record instanceof Student) {
            // Any COLLECTION blocks the purge, not merely a settled challan.
            //
            // `payments.challan_id` cascades on delete, so purging a student
            // hard-deletes their challans and takes every collection row with
            // them. Testing `status = 'paid'` let a student carrying a part
            // payment through: their challan is still flagged unpaid, so the
            // blocker stayed silent while the purge destroyed money the
            // institute had genuinely banked, leaving no trace in any report.
            //
            // This is the same reasoning that governs cancellation in
            // ChallanActions::cancel(); the two must agree or the rule is only
            // enforced on whichever path the operator happens to take.
            //
            // Asked of `challans.student_id` and NOT of `challan.admission`.
            // The chain through the admission is how this was written, and it
            // returned zero for a non-course charge — which has no admission —
            // so the guard fell silent on exactly the records it exists for:
            // purging a co-working tenant would have cascaded through their
            // challan, destroyed Rs 90,000 of real collections, and printed a
            // confirmation. That is the same defect as BUG-04, which this
            // method was written to fix, reappearing through a different
            // relation because the relation was the thing that changed.
            //
            // `student_id` states the owner outright and is populated on every
            // challan, enrolment or charge, so there is no chain left to break.
            $collected = (int) Payment::whereHas(
                'challan',
                fn ($q) => $q->where('student_id', $record->id)
            )->sum('amount');

            if ($collected > 0) {
                return 'This student has '.Format::money($collected)
                    .' of collections recorded against them. Purging would destroy those payment records and erase the money from every report. Remove the student instead, the record is hidden but the money trail survives.';
            }
        }

        if ($record instanceof User) {
            $enrolled = Admission::where('enrolled_by', $record->id)->count();

            if ($enrolled > 0) {
                return "This account signed {$enrolled} admission".($enrolled === 1 ? '' : 's')
                    .'. `enrolled_by` is the source of truth for who registered whom and is never editable, so the account cannot be destroyed. Remove it instead, the person loses access and the signatures stand.';
            }
        }

        if ($record instanceof Course) {
            $admissions = Admission::where('course_id', $record->id)->count();

            if ($admissions > 0) {
                return "This course has {$admissions} admission".($admissions === 1 ? '' : 's')
                    .' attached. Deactivate it instead, it disappears from the wizard while existing enrolments stay intact.';
            }
        }

        return null;
    }

    // ---- Tier 1: reversible removal -----------------------------------------

    /**
     * Soft-delete a record.
     *
     * @param  Model  $actor  the SuperAdmin (or User) carrying it out
     */
    public function remove(Model $record, Model $actor, string $reason = ''): void
    {
        DB::transaction(function () use ($record, $actor, $reason) {
            Audit::record($this->label($record).' removed', $actor, [
                'subject' => $record,
                'subject_label' => $this->describe($record),
                'field' => 'deleted_at',
                'old_value' => 'active',
                'new_value' => 'removed',
                'context' => array_filter([
                    'reason' => $reason ?: null,
                    'reversible' => true,
                ]),
            ]);

            // A removed staff account must also lose its session and its
            // "remember me" cookie, or removal is cosmetic until they log out.
            if ($record instanceof User) {
                $record->forceFill(['is_active' => false, 'deactivated_at' => now(), 'remember_token' => null])->save();
            }

            $record->delete();
        });
    }

    /** Undo a soft delete. */
    public function restore(Model $record, Model $actor): void
    {
        DB::transaction(function () use ($record, $actor) {
            $record->restore();

            // Restoring an account does NOT re-enable sign-in. Getting the
            // record back and handing someone their access back are different
            // decisions, and conflating them is how a dismissed employee ends
            // up logged in again after a routine data-recovery exercise.
            if ($record instanceof User) {
                $record->forceFill(['is_active' => false])->save();
            }

            Audit::record($this->label($record).' restored', $actor, [
                'subject' => $record,
                'subject_label' => $this->describe($record),
                'field' => 'deleted_at',
                'old_value' => 'removed',
                'new_value' => 'active',
                'context' => $record instanceof User
                    ? ['note' => 'Sign-in remains disabled until reactivated explicitly.']
                    : null,
            ]);
        });
    }

    // ---- Tier 2: irreversible purge ------------------------------------------

    /**
     * Permanently destroy a record and its dependents.
     *
     * The caller MUST have already passed {@see StepUp::confirm()} and a
     * type-to-confirm check; this method re-checks the blocker but does not
     * re-check identity, so it must never be reachable from a bare route.
     *
     * @throws RuntimeException when the record is not purgeable
     */
    public function purge(Model $record, Model $actor, string $reason = ''): void
    {
        if ($blocker = $this->purgeBlocker($record)) {
            throw new RuntimeException($blocker);
        }

        DB::transaction(function () use ($record, $actor, $reason) {
            // Snapshot FIRST. After this transaction there is nothing left to
            // describe, so the audit row is the only surviving evidence.
            Audit::record($this->label($record).' PURGED', $actor, [
                'subject' => $record,
                'subject_label' => $this->describe($record),
                'field' => 'record',
                'old_value' => 'existed',
                'new_value' => 'destroyed',
                'context' => array_filter([
                    'irreversible' => true,
                    // The stated reason is the only account of WHY this record
                    // no longer exists, worth as much as the snapshot of what
                    // it contained.
                    'reason' => $reason ?: null,
                    'snapshot' => $this->snapshot($record),
                ]),
            ]);

            // Cascade by hand rather than relying on DB-level cascades, so the
            // order is explicit and the money trail is removed in a defined
            // sequence instead of by whatever the engine decides.
            if ($record instanceof Student) {
                $admissionIds = Admission::where('student_id', $record->id)->pluck('id');
                Challan::whereIn('admission_id', $admissionIds)->delete();
                Admission::whereIn('id', $admissionIds)->delete();
            }

            $record->forceDelete();
        });
    }

    // ---- Helpers --------------------------------------------------------------

    /**
     * The exact string the operator must type to confirm a purge.
     *
     * Deliberately the record's own human-readable identifier rather than a
     * generic word like DELETE: typing "DELETE" becomes muscle memory, whereas
     * typing "Maha Asim" forces you to look at *which* record you are about to
     * destroy.
     */
    public function confirmationPhrase(Model $record): string
    {
        return match (true) {
            $record instanceof Student => $record->student_code,
            $record instanceof User => $record->username,
            $record instanceof Course => $record->code,
            default => (string) $record->getKey(),
        };
    }

    public function label(Model $record): string
    {
        return match (true) {
            $record instanceof Student => 'Student',
            $record instanceof User => 'Staff account',
            $record instanceof Course => 'Course',
            default => class_basename($record),
        };
    }

    public function describe(Model $record): string
    {
        return match (true) {
            $record instanceof Student => $record->student_code.' · '.$record->name,
            $record instanceof User => $record->username.' · '.$record->name,
            $record instanceof Course => $record->code.' · '.$record->title,
            default => (string) $record->getKey(),
        };
    }

    /**
     * A serialisable copy of the record for the audit trail, minus anything
     * that would be dangerous to keep once the account is gone.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Model $record): array
    {
        return collect($record->getAttributes())
            ->except(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])
            ->all();
    }
}
