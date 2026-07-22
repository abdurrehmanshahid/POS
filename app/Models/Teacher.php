<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Instructor (spec §2.6). Only created_at is tracked.
 */
class Teacher extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $fillable = ['name', 'phone', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'trainer_id');
    }
}
