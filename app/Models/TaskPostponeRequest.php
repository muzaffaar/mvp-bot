<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskPostponeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'requester_id',
        'decided_by',
        'amount',
        'unit',
        'minutes',
        'old_deadline',
        'requested_deadline',
        'status',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'old_deadline' => 'datetime',
            'requested_deadline' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'requester_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'decided_by');
    }
}
