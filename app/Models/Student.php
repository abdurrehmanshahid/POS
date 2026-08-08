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

    /**
     * What this student still owes across all their invoices.
     *
     * Summed over DISTINCT invoices, because one invoice can bill several
     * courses and adding it up per enrolment charges the same fee once per
     * course — a three-course student read as owing three times their fee.
     *
     * This number has now been wrong twice: once for reading `net_amount`
     * instead of `balance()`, so an advance already handed over was ignored,
     * and once for the double-count above. It appeared in three subtly
     * different forms across the staff screen, the owner console and the CSV
     * that goes to accounts, which is exactly how it got out of step. One
     * implementation, so the fourth surface cannot invent a fifth answer.
     *
     * Answers from the loaded relation, so callers that already eager load
     * `admissions.challan.payments` pay nothing extra.
     */
    public function outstanding(): int
    {
        return (int) $this->admissions
            ->where('status', '!=', 'cancelled')
            ->pluck('challan')
            ->filter()
            ->unique('id')
            ->sum(fn (Challan $challan) => $challan->balance());
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
