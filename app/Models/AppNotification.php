<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notification (spec §10). Filtered by the viewer's permissions/scope.
 */
class AppNotification extends Model
{
    protected $fillable = [
        'type', 'title', 'sub', 'student_id', 'challan_id',
        'is_revenue', 'is_admin', 'read_at',
    ];

    protected $casts = [
        'is_revenue' => 'boolean',
        'is_admin' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(Challan::class);
    }

    /**
     * Visibility filter (spec §6 / §10): hide revenue-flagged from users without
     * revenue.view, admin-flagged from users without scope.all, and any tied to
     * a student the user cannot see.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if (! $user->hasPermission('revenue.view')) {
            $q->where('is_revenue', false);
        }
        if (! $user->hasPermission('scope.all')) {
            $q->where('is_admin', false);
            // Only notifications with no student, or a student the officer enrolled.
            $q->where(function (Builder $w) use ($user) {
                $w->whereNull('student_id')
                    ->orWhereHas('student', function (Builder $s) use ($user) {
                        $s->whereHas('admissions', function (Builder $a) use ($user) {
                            $a->where('enrolled_by', $user->id)->where('status', '!=', 'cancelled');
                        });
                    });
            });
        }

        return $q;
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
