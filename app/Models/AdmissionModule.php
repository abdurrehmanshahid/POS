<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One module an enrolment actually bought, at the price it was sold for.
 *
 * `billed_amount` is a snapshot, not a lookup — see the migration
 * 2026_09_17_000002 for why re-reading the catalogue's current fee would
 * rewrite history on every voucher the institute has ever printed.
 */
class AdmissionModule extends Model
{
    protected $fillable = ['admission_id', 'course_module_id', 'billed_amount'];

    protected $casts = [
        'billed_amount' => 'integer',
    ];

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /**
     * The catalogue row this was sold from.
     *
     * `withTrashed()` for the reason {@see Admission::enroller()} gives: the
     * voucher has to be able to say the module's name out loud long after the
     * catalogue stopped offering it.
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id')->withTrashed();
    }
}
