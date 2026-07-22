<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One enrolment: student × course (spec §2.7). `enrolled_by` is the source of
 * truth for who registered whom and the anchor for officer scoping.
 */
class Admission extends Model
{
    use HasFactory;

    protected $fillable = [
        'reg_no', 'student_id', 'course_id', 'enrolled_by', 'status', 'rejection_reason',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function enroller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    public function challan(): HasOne
    {
        return $this->hasOne(Challan::class);
    }

    // ---- Scopes ------------------------------------------------------------

    /** Officers see only their own enrolments; admins (scope.all) see all. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->hasPermission('scope.all')) {
            return $q;
        }

        return $q->where('enrolled_by', $user->id);
    }

    /** Not cancelled, counts toward money totals and active lists. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', '!=', 'cancelled');
    }
}
