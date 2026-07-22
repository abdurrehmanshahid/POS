<?php

namespace App\Models;

use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student record (spec §2.4), created once, reused across enrolments.
 */
class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'student_code', 'type', 'name', 'guardian_name', 'cnic', 'phone', 'created_by',
    ];

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ---- Scoping (spec §6) -------------------------------------------------

    /**
     * Officers (no scope.all) see only students who appear in their own
     * non-cancelled admissions; admins see every student.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->hasPermission('scope.all')) {
            return $q;
        }

        return $q->whereHas('admissions', function (Builder $a) use ($user) {
            $a->where('enrolled_by', $user->id)->where('status', '!=', 'cancelled');
        });
    }

    // ---- Display -----------------------------------------------------------

    public function initials(): string
    {
        return Format::initials($this->name);
    }

    public function typeLabel(): string
    {
        return $this->type === 'T' ? 'Track' : 'Regular';
    }
}
