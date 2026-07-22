<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Challan;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Writes the append-only activity trail (spec §2.10, generalised).
 *
 * Every consequential act goes through here: money changes on a challan, and
 * also sign-ins, staff edits, password resets, deletions, purges, impersonation
 * and settings changes.
 *
 * Two rules this class exists to enforce:
 *
 *  1. **Actor identity is resolved, not declared.** The caller hands over a
 *     model and we work out from its class whether it is staff, a super admin
 *     or the system, then snapshot the name. A caller cannot mislabel who acted.
 *
 *  2. **Write before you destroy.** For anything irreversible, the audit row,
 *     including a full snapshot of the doomed record in `context`, is
 *     committed before the delete runs, inside the same transaction. Audit the
 *     intent, then carry it out.
 */
class Audit
{
    // ---- Challan money trail (spec §2.10, behaviour unchanged) --------------

    public static function log(
        Challan $challan,
        User $actor,
        string $action,
        string $field,
        string $old,
        string $new,
        ?Carbon $at = null,
    ): AuditLog {
        return self::record($action, $actor, [
            'challan_id' => $challan->id,
            'subject' => $challan,
            'subject_label' => $challan->challan_no,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'created_at' => $at,
        ]);
    }

    public static function issued(Challan $challan, User $actor, ?Carbon $at = null): void
    {
        self::log($challan, $actor, 'Challan issued', 'base_amount', 'Rs 0', Format::money($challan->base_amount), $at);
    }

    public static function discountApplied(Challan $challan, User $actor, ?Carbon $at = null): void
    {
        self::log($challan, $actor, 'Discount applied', 'discount_amount', 'Rs 0', Format::money($challan->discount_amount), $at);
    }

    public static function markedPaid(Challan $challan, User $actor, string $via, ?Carbon $at = null): void
    {
        self::log($challan, $actor, 'Marked paid', 'status', 'unpaid', 'paid via '.$via, $at);
    }

    public static function cancelled(Challan $challan, User $actor, ?Carbon $at = null): void
    {
        self::log($challan, $actor, 'Registration cancelled', 'status', $challan->status, 'cancelled', $at);
    }

    // ---- General activity trail --------------------------------------------

    /**
     * Record any event.
     *
     * @param  Model|null  $actor  User, SuperAdmin, or null for system events.
     * @param  array{
     *     challan_id?: int|null,
     *     subject?: Model|null,
     *     subject_label?: string|null,
     *     field?: string|null,
     *     old_value?: string|null,
     *     new_value?: string|null,
     *     context?: array<string, mixed>|null,
     *     created_at?: Carbon|null,
     * }  $attrs
     */
    public static function record(string $action, ?Model $actor = null, array $attrs = []): AuditLog
    {
        $subject = $attrs['subject'] ?? null;
        $request = request();
        $context = $attrs['context'] ?? null;

        // Impersonation must not launder attribution. When a super admin is
        // driving an officer's session, the domain data legitimately records the
        // officer (an admission really is enrolled by them), but the audit trail
        // records who was actually at the keyboard. Both are true; we keep both.
        $impersonator = app(Impersonation::class)->impersonator();
        if ($impersonator && $actor instanceof User) {
            $context = array_merge($context ?? [], [
                'performed_via_impersonation_by' => $impersonator->name,
                'acting_as' => $actor->name,
            ]);
        }

        return AuditLog::create([
            'challan_id' => $attrs['challan_id'] ?? null,

            'actor_id' => $actor?->getKey(),
            'actor_type' => self::actorType($actor),
            'actor_name' => $impersonator && $actor instanceof User
                ? $actor->name.' (as directed by '.$impersonator->name.')'
                : $actor?->name,

            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $attrs['subject_label'] ?? null,

            'action' => $action,
            'field' => $attrs['field'] ?? null,
            'old_value' => $attrs['old_value'] ?? null,
            'new_value' => $attrs['new_value'] ?? null,
            'context' => $context,

            // Forensics. The user-agent is clamped to the column width because
            // a spoofed multi-kilobyte header must not be able to break the
            // insert, and a failed audit write would abort the very
            // transaction it is meant to be recording.
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null,

            'created_at' => $attrs['created_at'] ?? now(),
        ]);
    }

    /**
     * Which guard the actor belongs to, derived from the model class so that a
     * caller can never claim to be something it is not.
     */
    private static function actorType(?Model $actor): string
    {
        return match (true) {
            $actor instanceof SuperAdmin => 'superadmin',
            $actor instanceof User => 'user',
            default => 'system',
        };
    }
}
