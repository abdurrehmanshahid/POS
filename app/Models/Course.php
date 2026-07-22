<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catalog course (spec §2.5). Fee is a whole-rupee integer; capacity null =
 * unlimited seats.
 */
class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['code', 'title', 'trainer_id', 'fee', 'capacity', 'is_active'];

    protected $casts = [
        'fee' => 'integer',
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'trainer_id');
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function cohorts(): HasMany
    {
        return $this->hasMany(Cohort::class);
    }

    /** The intake new enrolments on this course join, if one is open. */
    public function openCohort(): ?Cohort
    {
        return $this->relationLoaded('cohorts')
            ? $this->cohorts->firstWhere('is_open', true)
            : $this->cohorts()->where('is_open', true)->first();
    }

    // ---- Seats / capacity (spec §7.9) --------------------------------------

    /** Seats consumed = validated admissions. Uses the loaded collection when present. */
    public function seatsUsed(): int
    {
        if ($this->relationLoaded('admissions')) {
            return $this->admissions->where('status', 'validated')->count();
        }

        return $this->admissions()->where('status', 'validated')->count();
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->seatsUsed() >= $this->capacity;
    }

    /** Seats remaining, or null when uncapped. */
    public function seatsLeft(): ?int
    {
        return $this->capacity === null ? null : $this->capacity - $this->seatsUsed();
    }
}
