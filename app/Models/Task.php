<?php

namespace App\Models;

use App\Enums\TaskAssignmentType;
use App\Enums\TaskPriority;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'task_number',
        'status',
        'title',
        'description',
        'author_id',
        'assignor_id',
        'assignee_id',
        'group_id',
        'assignment_type',
        'priority',
        'deadline',
        'source_type',
        'source_id',
        'source_message_id',
        'source_url',
        'ajralish_aniqligi',
        'started_at',
        'completed_at',
        'closed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'assignment_type' => TaskAssignmentType::class,
            'source_type' => TaskSourceType::class,

            'deadline' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',

            'metadata' => 'array',
            'ajralish_aniqligi' => 'float',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Staff relationships
    |--------------------------------------------------------------------------
    */

    public function author(): BelongsTo
    {
        return $this->belongsTo(
            Staff::class,
            'author_id'
        );
    }

    public function assignor(): BelongsTo
    {
        return $this->belongsTo(
            Staff::class,
            'assignor_id'
        );
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(
            Staff::class,
            'assignee_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Task relationships
    |--------------------------------------------------------------------------
    */

    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function postponeRequests(): HasMany
    {
        return $this->hasMany(TaskPostponeRequest::class);
    }

    public function sprints(): BelongsToMany
    {
        return $this->belongsToMany(
            Sprint::class,
            'task_sprint'
        )->withPivot([
            'added_at',
            'removed_at',
        ])->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Staff task relationships
    |--------------------------------------------------------------------------
    */

    public function authoredTasks(): HasMany
    {
        return $this->hasMany(
            Task::class,
            'author_id'
        );
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(
            Task::class,
            'assignee_id'
        );
    }

    public function assignedByTasks(): HasMany
    {
        return $this->hasMany(
            Task::class,
            'assignor_id'
        );
    }

    public function efficiencies(): HasMany
    {
        return $this->hasMany(
            StaffEfficiency::class
        );
    }

    public function createdSprints(): HasMany
    {
        return $this->hasMany(
            Sprint::class,
            'created_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Assignment helpers
    |--------------------------------------------------------------------------
    */

    public function isDirectlyAssigned(): bool
    {
        return $this->assignment_type === TaskAssignmentType::DIRECT
            && $this->assignee_id !== null;
    }

    /**
     * Open task that can be accepted by an eligible
     * member of the current Telegram chat.
     *
     * GROUP is currently retained as the enum value for
     * backward compatibility. It no longer refers to an
     * App\Models\Group entity.
     */
    public function isOpenForGroupAcceptance(): bool
    {
        return $this->assignment_type === TaskAssignmentType::GROUP
            && $this->assignee_id === null;
    }

    public function isAssigned(): bool
    {
        return $this->assignee_id !== null;
    }

    /**
     * Telegram membership/authorization is handled outside
     * the Task model.
     *
     * The Task model only determines whether this task is
     * open for acceptance.
     */
    public function canBeAcceptedBy(Staff $staff): bool
    {
        return $this->isOpenForGroupAcceptance()
            && $staff->status === 'active';
    }
}
