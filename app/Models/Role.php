<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named permission set (spec §2.2). String primary key: `admin`, `officer`,
 * or a generated `role_<slug>_<3digits>` for custom roles.
 */
class Role extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'is_system', 'tone', 'requires_2fa'];

    protected $casts = ['is_system' => 'boolean', 'requires_2fa' => 'boolean'];

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->permissions->pluck('permission_key')->all();
    }

    public function hasPermission(string $key): bool
    {
        return $this->permissions->contains('permission_key', $key);
    }

    /** Generate a stable id for a new custom role (spec §4.3). */
    public static function generateId(string $name): string
    {
        $slug = Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');

        return 'role_'.$slug.'_'.random_int(100, 999);
    }
}
