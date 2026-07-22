<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable row in the activity trail (spec §2.10, generalised).
 *
 * Append-only is enforced in code as well as by convention: `booted()` below
 * blocks updates and deletes outright, so no future controller, Livewire
 * component or tinker session can quietly rewrite history. If the trail ever
 * needs pruning that must be a deliberate, separate maintenance command.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'challan_id',
        'actor_id', 'actor_type', 'actor_name',
        'subject_type', 'subject_id', 'subject_label',
        'action', 'field', 'old_value', 'new_value', 'context',
        'ip_address', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'context' => 'array',
    ];

    /**
     * Make the table genuinely append-only. An audit row that can be edited is
     * not evidence of anything.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Audit rows are immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Audit rows cannot be deleted.'));
    }

    // ---- Relationships -----------------------------------------------------

    /**
     * Deliberately NOT a foreign key at the database level (see the migration).
     * The relationship is a convenience for display and returns null once the
     * challan is gone, the row's own snapshot columns carry the meaning.
     */
    public function challan(): BelongsTo
    {
        return $this->belongsTo(Challan::class);
    }

    /** Resolves only for staff actors; super admins live in another table. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    // ---- Display -----------------------------------------------------------

    /**
     * Who did it, preferring the snapshot so the trail still reads correctly
     * after the actor has been purged.
     */
    public function actorLabel(): string
    {
        $name = $this->actor_name ?: ($this->actor?->name ?? 'Unknown');

        return match ($this->actor_type) {
            'superadmin' => $name.' (super admin)',
            'system' => 'System',
            default => $name,
        };
    }

    // ---- Scopes ------------------------------------------------------------

    /** The challan drawer's timeline (spec §2.10), unchanged by generalisation. */
    public function scopeForChallan(Builder $q, int $challanId): Builder
    {
        return $q->where('challan_id', $challanId)->orderBy('created_at');
    }
}
