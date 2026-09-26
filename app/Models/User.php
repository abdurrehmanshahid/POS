<?php

namespace App\Models;

use App\Support\Format;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A staff login (spec §2.1). Role is a thin string key into `roles`; all access
 * derives from the role's permission set, never from the role name.
 *
 * Soft-deleted rather than deleted: a removed officer's name still has to
 * resolve on every admission they ever signed (`admissions.enrolled_by` is the
 * source of truth for who registered whom, and is never editable). Permanent
 * removal is the super admin's separate, password-confirmed purge.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'username', 'email', 'phone', 'password',
        'role_id', 'is_active', 'must_reset_password',
    ];

    protected $hidden = [
        'password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_reset_password' => 'boolean',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    // ---- Relationships -----------------------------------------------------

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Admissions this user created (the scoping anchor). */
    public function admissionsEnrolled(): HasMany
    {
        return $this->hasMany(Admission::class, 'enrolled_by');
    }

    /** Student records this user first created. */
    public function studentsCreated(): HasMany
    {
        return $this->hasMany(Student::class, 'created_by');
    }

    // ---- Permissions -------------------------------------------------------

    /** True when the user's role grants $key. Underlies Gate::before / can(). */
    public function hasPermission(string $key): bool
    {
        return (bool) $this->role?->hasPermission($key);
    }

    // ---- Display -----------------------------------------------------------

    public function initials(): string
    {
        return Format::initials($this->name);
    }

    public function roleLabel(): string
    {
        return $this->role?->name ?? '';
    }
}
