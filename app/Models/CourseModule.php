<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One sellable part of a course — "Module 2", at its own fee.
 *
 * A course either has modules or it does not. When it does, the full course is
 * exactly the sum of them (see {@see Course::priceFor()}): there is no separate
 * bundle price to keep in step, so the catalogue cannot come to disagree with
 * itself about what the whole thing costs.
 */
class CourseModule extends Model
{
    use SoftDeletes;

    protected $fillable = ['course_id', 'seq', 'title', 'fee', 'is_active'];

    protected $casts = [
        'seq' => 'integer',
        'fee' => 'integer',
        'is_active' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function admissionModules(): HasMany
    {
        return $this->hasMany(AdmissionModule::class);
    }

    /** Modules that may still be sold. Retired ones stay on old vouchers. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Has anybody bought this module?
     *
     * The courses screen asks before offering to remove one: a module with
     * enrolments behind it is retired, never deleted, or the vouchers that
     * already list it lose the line that explains their own total.
     */
    public function hasBeenSold(): bool
    {
        return $this->relationLoaded('admissionModules')
            ? $this->admissionModules->isNotEmpty()
            : $this->admissionModules()->exists();
    }
}
