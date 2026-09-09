<?php

namespace App\Models;

use App\Enums\TaskLogEventType;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskLog extends Model
{
    use HasFactory;

    /*
     * Task logs are immutable audit records.
     * Therefore we don't use updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_id',
        'actor_id',
        'event_type',
        'from_status',
        'to_status',
        'from_assignee_id',
        'to_assignee_id',
        'message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => TaskLogEventType::class,
            'from_status' => TaskStatus::class,
            'to_status' => TaskStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    } 

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    // withTrashed(): these are immutable audit records, so a staff member
    // who has since left (soft-deleted) must still resolve here by name.
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'actor_id')->withTrashed();
    }

    public function fromAssignee(): BelongsTo
    {
        return $this->belongsTo(
            Staff::class,
            'from_assignee_id'
        )->withTrashed();
    }

    public function toAssignee(): BelongsTo
    {
        return $this->belongsTo(
            Staff::class,
            'to_assignee_id'
        )->withTrashed();
    }
}
