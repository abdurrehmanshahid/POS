<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily per-student attendance (spec §2.11). Backs the Reports summary cards.
 */
class Attendance extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['course_id', 'student_id', 'session_date', 'status', 'marked_by'];

    protected $casts = ['session_date' => 'date'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
