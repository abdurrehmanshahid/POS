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
        'student_code', 'type', 'kind', 'name', 'guardian_name', 'cnic', 'phone',
        'created_by', 'import_key',
    ];

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    /**
     * Every invoice raised against this person, enrolment or charge alike.
     *
     * `admissions.challan` reaches only the first kind. A contact has no
     * admission at all, so without this relation their money is unreachable
     * from the person it belongs to.
     */
    public function challans(): HasMany
    {
        return $this->hasMany(Challan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ---- Student or contact ------------------------------------------------

    /**
     * Someone who has only ever bought a non-course service — a desk, a
     * certificate, a recovery settlement. See the `kind` migration for why they
     * are on this table at all rather than one of their own.
     */
    public function isContact(): bool
    {
        return $this->kind === 'contact';
    }

    /**
     * Only the people the institute is actually teaching.
     *
     * Every headline count and every roster goes through this. Contacts are
     * excluded by DEFAULT and included only by asking, because the failure that
     * matters is the silent one: a screen that forgets to filter reports a room
     * tenant as a student, and nobody can see from the number that it did.
     */
    public function scopeStudents(Builder $q): Builder
    {
        return $q->where('students.kind', 'student');
    }

    public function scopeContacts(Builder $q): Builder
    {
        return $q->where('students.kind', 'contact');
    }

    // ---- Scoping (spec §6) -------------------------------------------------

    /**
     * Officers (no scope.all) see only their own people; admins see everyone.
     *
     * Two ways to be someone's own, because there are now two ways to acquire a
     * person. `admissions.enrolled_by` is the original and covers every student;
     * `challans.raised_by` covers a contact, who has no admission and would
     * otherwise be invisible to the very officer who created them — billed,
     * collected from, and then unfindable on the screen where they would go to
     * collect the rest.
     *
     * The same pairing as `Ledger::scopedChallans()`, and deliberately so: the
     * person and their money must be visible to the same people, or an officer
     * sees a balance on the dashboard belonging to somebody they cannot open.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->hasPermission('scope.all')) {
            return $q;
        }

        return $q->where(function (Builder $mine) use ($user) {
            $mine->whereHas('admissions', function (Builder $a) use ($user) {
                $a->where('enrolled_by', $user->id)->where('status', '!=', 'cancelled');
            })->orWhereHas('challans', function (Builder $c) use ($user) {
                $c->whereNull('admission_id')->where('raised_by', $user->id);
            });
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
     * Charges are added on top, from the `challans` relation, because they have
     * no admission to be reached through and would otherwise read as zero: a
     * contact owing Rs 15,000 for a desk would show a clean slate on the very
     * screen an officer opens to chase them. The two branches cannot overlap —
     * one takes only invoices WITH an anchor admission, the other only those
     * without — so nothing is counted twice.
     *
     * Answers from the loaded relations, so callers that eager load
     * `admissions.challan.payments` and apply {@see scopeWithCharges()} pay
     * nothing extra.
     */
    public function outstanding(): int
    {
        $enrolments = $this->admissions
            ->where('status', '!=', 'cancelled')
            ->pluck('challan')
            ->filter()
            ->unique('id');

        $charges = $this->challans->whereNull('admission_id');

        return (int) $enrolments->concat($charges)
            ->sum(fn (Challan $challan) => $challan->balance());
    }

    /**
     * The charge half of `outstanding()`, eager loaded.
     *
     * Deliberately only this half. The enrolment half is loaded differently by
     * each of the three screens that show the figure — the CSV export narrows
     * `admissions` to the exporting officer's own, and must keep doing so —
     * whereas the charge relation is identical everywhere, so it is the part
     * worth having one name for. A screen that lists students and forgets this
     * gets the right number and one query per row, which is the failure that
     * hides until the roll is 478 long.
     */
    public function scopeWithCharges(Builder $q): Builder
    {
        return $q->with(['challans' => fn ($c) => $c->whereNull('admission_id')->with('payments')]);
    }

    // ---- Display -----------------------------------------------------------

    public function initials(): string
    {
        return Format::initials($this->name);
    }

    /**
     * Regular or Track — which fee structure applies.
     *
     * Meaningless for a contact, who is on no structure at all, so it says
     * "Contact" instead of picking one of two answers that are both wrong.
     */
    public function typeLabel(): string
    {
        if ($this->isContact()) {
            return 'Contact';
        }

        return $this->type === 'T' ? 'Track' : 'Regular';
    }
}
