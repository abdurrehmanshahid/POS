<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

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

    /**
     * The parts this course is sold in, in the order they are taught.
     *
     * Empty for a course priced whole, which is every course that predates
     * 2026_09_17_000002 and every course nobody has divided up since.
     */
    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('seq');
    }

    // ---- Pricing -----------------------------------------------------------

    /** Is this course sold by the module rather than whole? */
    public function hasModules(): bool
    {
        return $this->relationLoaded('modules')
            ? $this->modules->isNotEmpty()
            : $this->modules()->exists();
    }

    /** The modules an officer may still put on a new registration. */
    public function sellableModules(): Collection
    {
        return $this->relationLoaded('modules')
            ? $this->modules->where('is_active', true)->values()
            : $this->modules()->sellable()->get();
    }

    /**
     * What this course costs for the modules named, or whole.
     *
     * The three cases, and why they are these three:
     *
     *   no modules          `courses.fee`, exactly as before. Modules are
     *                       opt-in and most of the catalogue has none.
     *   modules, none named the full course — which IS the sum of every
     *                       sellable module. There is no separate bundle
     *                       price, so "full course" cannot drift away from
     *                       what its parts add up to.
     *   modules, some named the sum of those.
     *
     * Unknown or retired ids contribute nothing here; validation refuses them
     * outright in RegistrationService rather than quietly under-charging.
     *
     * @param  list<int>|null  $moduleIds  null or empty = the whole course
     */
    public function priceFor(?array $moduleIds = null): int
    {
        $modules = $this->sellableModules();

        if ($modules->isEmpty()) {
            return (int) $this->fee;
        }

        if ($moduleIds === null || $moduleIds === []) {
            return (int) $modules->sum('fee');
        }

        return (int) $modules->whereIn('id', $moduleIds)->sum('fee');
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
