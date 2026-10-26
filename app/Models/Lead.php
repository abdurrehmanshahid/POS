<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A prospect working its way towards enrolment. See the leads migration.
 */
class Lead extends Model
{
    use SoftDeletes;

    /** Every stage a lead can be in, with its label, in pipeline order. */
    public const STAGES = [
        'unread' => 'Unread',
        'contacted' => 'Contacted',
        'outline_shared' => 'Outline shared',
        'demo' => 'Demo / workshop',
        'interested' => 'Interested',
        'not_interested' => 'Not interested',
        'won' => 'Won',
        'closed' => 'Closed',
    ];

    /** The tabs on the Leads screen, each showing one or more stages. */
    public const TABS = [
        'unread' => ['Initial (Unread)', ['unread']],
        'contacted' => ['Contacted', ['contacted']],
        'outline_shared' => ['Outline shared', ['outline_shared']],
        'demo' => ['Demo / workshop', ['demo']],
        'interest' => ['Interested / Not interested', ['interested', 'not_interested']],
        'done' => ['Won & closed', ['won', 'closed']],
    ];

    protected $fillable = [
        'name', 'phone', 'email', 'source', 'external_id', 'course_interest',
        'notes', 'stage', 'stage_changed_at', 'created_by',
    ];

    protected $casts = ['stage_changed_at' => 'datetime'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }
}
