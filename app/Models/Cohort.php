<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One run of one course, the "Batch # 11" on the fee invoice.
 *
 * Membership hangs off the admission rather than the student, so a person can
 * be in a different batch for every course they take.
 */
class Cohort extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'course_id', 'starts_on', 'ends_on', 'capacity', 'is_open', 'created_by'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'capacity' => 'integer',
        'is_open' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The intake new enrolments join. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_open', true);
    }

    // ---- Derived -----------------------------------------------------------

    /** Seats consumed, counted the same way a course counts them. */
    public function seatsUsed(): int
    {
        return $this->relationLoaded('admissions')
            ? $this->admissions->where('status', '!=', 'cancelled')->count()
            : $this->admissions()->where('status', '!=', 'cancelled')->count();
    }

    public function seatsLeft(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->seatsUsed());
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->seatsUsed() >= $this->capacity;
    }

    /** "Batch # 11 · SHOP-101", the label a challan and a drawer both want. */
    public function label(): string
    {
        return $this->name.($this->course ? ' · '.$this->course->code : '');
    }
}
