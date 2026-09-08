<?php

namespace Tests\Feature\Task;

use App\Enums\TaskAssignmentType;
use App\Enums\TaskLogEventType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Group;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskLog;
use App\Services\Task\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TaskService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createStaff(
        string $name = 'Test Staff',
    ): Staff {
        return Staff::factory()->create([
            'full_name' => $name,
            'status' => 'active',
        ]);
    }

    private function createGroup(
        string $name = 'Test Group',
    ): Group {
        return Group::factory()->create([
            'name' => $name,
        ]);
    }

    private function addStaffToGroup(
        Group $group,
        Staff $staff,
    ): void {
        $group->staff()->attach($staff->id, [
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function createDirectTask(
        Staff $actor,
        Group $group,
        Staff $assignee,
    ): Task {
        return $this->service->create(
            [
                'title' => 'Test direct task',
                'description' => 'Test description',
                'group_id' => $group->id,
                'assignee_id' => $assignee->id,
                'assignment_type' => TaskAssignmentType::DIRECT,
                'priority' => TaskPriority::NORMAL,
            ],
            $actor,
        );
    }

    private function createGroupTask(
        Staff $actor,
        Group $group,
    ): Task {
        return $this->service->create(
            [
                'title' => 'Test group task',
                'description' => 'Test group description',
                'group_id' => $group->id,
                'assignment_type' => TaskAssignmentType::GROUP,
                'priority' => TaskPriority::NORMAL,
            ],
            $actor,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Creation
    |--------------------------------------------------------------------------
    */

    public function test_direct_task_is_created_and_assigned(): void
    {
        $actor = $this->createStaff('Leader');
        $assignee = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $actor);
        $this->addStaffToGroup($group, $assignee);

        $task = $this->createDirectTask(
            actor: $actor,
            group: $group,
            assignee: $assignee,
        );

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'group_id' => $group->id,
            'author_id' => $actor->id,
            'assignor_id' => $actor->id,
            'assignee_id' => $assignee->id,
            'assignment_type' => TaskAssignmentType::DIRECT->value,
            'status' => TaskStatus::ASSIGNED->value,
            'title' => 'Test direct task',
        ]);

        $this->assertNotNull($task->task_number);

        $this->assertStringStartsWith(
            'TASK-' . now()->year . '-',
            $task->task_number,
        );
    }

    public function test_direct_task_creates_created_and_assigned_logs(): void
    {
        $actor = $this->createStaff('Leader');
        $assignee = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $actor);
        $this->addStaffToGroup($group, $assignee);

        $task = $this->createDirectTask(
            $actor,
            $group,
            $assignee,
        );

        $this->assertDatabaseHas('task_logs', [
            'task_id' => $task->id,
            'actor_id' => $actor->id,
            'event_type' => TaskLogEventType::CREATED->value,
            'to_status' => TaskStatus::ASSIGNED->value,
            'to_assignee_id' => $assignee->id,
        ]);

        $this->assertDatabaseHas('task_logs', [
            'task_id' => $task->id,
            'actor_id' => $actor->id,
            'event_type' => TaskLogEventType::ASSIGNED->value,
            'to_status' => TaskStatus::ASSIGNED->value,
            'to_assignee_id' => $assignee->id,
        ]);
    }

    public function test_group_task_is_created_without_assignee(): void
    {
        $actor = $this->createStaff('Leader');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $actor);

        $task = $this->createGroupTask(
            actor: $actor,
            group: $group,
        );

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'group_id' => $group->id,
            'assignee_id' => null,
            'assignment_type' => TaskAssignmentType::GROUP->value,
            'status' => TaskStatus::AWAITING_ACCEPTANCE->value,
        ]);
    }

    public function test_group_task_creates_published_to_group_log(): void
    {
        $actor = $this->createStaff('Leader');
        $group = $this->createGroup('Finance');

        $this->addStaffToGroup($group, $actor);

        $task = $this->createGroupTask(
            actor: $actor,
            group: $group,
        );

        $this->assertDatabaseHas('task_logs', [
            'task_id' => $task->id,
            'actor_id' => $actor->id,
            'event_type' => TaskLogEventType::PUBLISHED_TO_GROUP->value,
            'to_status' => TaskStatus::AWAITING_ACCEPTANCE->value,
        ]);
    }

    public function test_direct_task_requires_assignee(): void
    {
        $this->expectException(ValidationException::class);

        $actor = $this->createStaff();
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $actor);

        $this->service->create(
            [
                'title' => 'Task without assignee',
                'group_id' => $group->id,
                'assignment_type' => TaskAssignmentType::DIRECT,
            ],
            $actor,
        );
    }

    public function test_group_task_cannot_have_assignee(): void
    {
        $this->expectException(ValidationException::class);

        $actor = $this->createStaff('Leader');
        $assignee = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $actor);
        $this->addStaffToGroup($group, $assignee);

        $this->service->create(
            [
                'title' => 'Invalid group task',
                'group_id' => $group->id,
                'assignment_type' => TaskAssignmentType::GROUP,
                'assignee_id' => $assignee->id,
            ],
            $actor,
        );
    }

    public function test_direct_task_assignee_must_belong_to_group(): void
    {
        $this->expectException(ValidationException::class);

        $actor = $this->createStaff('Leader');
        $assignee = $this->createStaff('Outside Worker');

        $group = $this->createGroup();

        $this->addStaffToGroup($group, $actor);

        // Intentionally NOT adding $assignee to the group.

        $this->createDirectTask(
            actor: $actor,
            group: $group,
            assignee: $assignee,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Group task acceptance
    |--------------------------------------------------------------------------
    */

    public function test_group_task_can_be_accepted_by_group_member(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createGroupTask(
            actor: $leader,
            group: $group,
        );

        $acceptedTask = $this->service->accept(
            task: $task,
            staff: $worker,
        );

        $this->assertSame(
            $worker->id,
            $acceptedTask->assignee_id,
        );

        $this->assertSame(
            TaskStatus::ACCEPTED,
            $acceptedTask->status,
        );

        $this->assertSame(
            TaskAssignmentType::DIRECT,
            $acceptedTask->assignment_type,
        );

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'assignee_id' => $worker->id,
            'assignment_type' => TaskAssignmentType::DIRECT->value,
            'status' => TaskStatus::ACCEPTED->value,
        ]);
    }

    public function test_accepting_group_task_creates_acceptance_log(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createGroupTask(
            $leader,
            $group,
        );

        $this->service->accept(
            task: $task,
            staff: $worker,
        );

        $this->assertDatabaseHas('task_logs', [
            'task_id' => $task->id,
            'actor_id' => $worker->id,
            'event_type' => TaskLogEventType::ACCEPTED->value,
            'from_status' => TaskStatus::AWAITING_ACCEPTANCE->value,
            'to_status' => TaskStatus::ACCEPTED->value,
            'from_assignee_id' => null,
            'to_assignee_id' => $worker->id,
        ]);
    }

    public function test_non_group_member_cannot_accept_task(): void
    {
        $leader = $this->createStaff('Leader');
        $outsider = $this->createStaff('Outsider');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);

        $task = $this->createGroupTask(
            $leader,
            $group,
        );

        $this->expectException(ValidationException::class);

        $this->service->accept(
            task: $task,
            staff: $outsider,
        );
    }

    public function test_already_accepted_group_task_cannot_be_accepted_again(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $secondWorker = $this->createStaff('Second Worker');

        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);
        $this->addStaffToGroup($group, $secondWorker);

        $task = $this->createGroupTask(
            $leader,
            $group,
        );

        $this->service->accept(
            task: $task,
            staff: $worker,
        );

        $this->expectException(ValidationException::class);

        $this->service->accept(
            task: $task->fresh(),
            staff: $secondWorker,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Assignment / reassignment
    |--------------------------------------------------------------------------
    */

    public function test_assign_assigns_unassigned_task(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createGroupTask(
            $leader,
            $group,
        );

        $assignedTask = $this->service->assign(
            task: $task,
            assignee: $worker,
            actor: $leader,
        );

        $this->assertSame(
            $worker->id,
            $assignedTask->assignee_id,
        );

        $this->assertSame(
            TaskStatus::ASSIGNED,
            $assignedTask->status,
        );

        $this->assertSame(
            TaskAssignmentType::DIRECT,
            $assignedTask->assignment_type,
        );
    }

    public function test_reassign_changes_assignee(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $newWorker = $this->createStaff('New Worker');

        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);
        $this->addStaffToGroup($group, $newWorker);

        $task = $this->createDirectTask(
            $leader,
            $group,
            $worker,
        );

        $updatedTask = $this->service->reassign(
            task: $task,
            newAssignee: $newWorker,
            actor: $leader,
        );

        $this->assertSame(
            $newWorker->id,
            $updatedTask->assignee_id,
        );

        $this->assertDatabaseHas('task_logs', [
            'task_id' => $task->id,
            'actor_id' => $leader->id,
            'event_type' => TaskLogEventType::REASSIGNED->value,
            'from_assignee_id' => $worker->id,
            'to_assignee_id' => $newWorker->id,
        ]);
    }

    public function test_reassign_cannot_assign_staff_outside_group(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $outsider = $this->createStaff('Outsider');

        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createDirectTask(
            $leader,
            $group,
            $worker,
        );

        $this->expectException(ValidationException::class);

        $this->service->reassign(
            task: $task,
            newAssignee: $outsider,
            actor: $leader,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status lifecycle
    |--------------------------------------------------------------------------
    */

    public function test_assigned_task_can_be_started(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createDirectTask(
            $leader,
            $group,
            $worker,
        );

        $updatedTask = $this->service->changeStatus(
            task: $task,
            newStatus: TaskStatus::IN_PROGRESS,
            actor: $worker,
        );

        $this->assertSame(
            TaskStatus::IN_PROGRESS,
            $updatedTask->status,
        );

        $this->assertNotNull(
            $updatedTask->started_at,
        );

        $this->assertDatabaseHas('task_logs', [
            'task_id' => $task->id,
            'actor_id' => $worker->id,
            'event_type' => TaskLogEventType::STARTED->value,
            'from_status' => TaskStatus::ASSIGNED->value,
            'to_status' => TaskStatus::IN_PROGRESS->value,
        ]);
    }

    public function test_accepted_task_can_be_started(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createGroupTask(
            $leader,
            $group,
        );

        $task = $this->service->accept(
            $task,
            $worker,
        );

        $task = $this->service->changeStatus(
            task: $task,
            newStatus: TaskStatus::IN_PROGRESS,
            actor: $worker,
        );

        $this->assertSame(
            TaskStatus::IN_PROGRESS,
            $task->status,
        );

        $this->assertNotNull(
            $task->started_at,
        );
    }

    public function test_in_progress_task_can_be_closed(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createDirectTask(
            $leader,
            $group,
            $worker,
        );

        $task = $this->service->changeStatus(
            task: $task,
            newStatus: TaskStatus::IN_PROGRESS,
            actor: $worker,
        );

        $task = $this->service->changeStatus(
            task: $task,
            newStatus: TaskStatus::CLOSED,
            actor: $worker,
        );

        $this->assertSame(
            TaskStatus::CLOSED,
            $task->status,
        );

        $this->assertNotNull(
            $task->completed_at,
        );

        $this->assertNotNull(
            $task->closed_at,
        );

        $this->assertDatabaseHas('task_logs', [
            'task_id' => $task->id,
            'actor_id' => $worker->id,
            'event_type' => TaskLogEventType::CLOSED->value,
            'from_status' => TaskStatus::IN_PROGRESS->value,
            'to_status' => TaskStatus::CLOSED->value,
        ]);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createDirectTask(
            $leader,
            $group,
            $worker,
        );

        $this->expectException(ValidationException::class);

        $this->service->changeStatus(
            task: $task,
            newStatus: TaskStatus::CLOSED,
            actor: $worker,
        );
    }

    public function test_closed_task_cannot_change_status(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createDirectTask(
            $leader,
            $group,
            $worker,
        );

        $task->update([
            'status' => TaskStatus::CLOSED,
            'closed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus(
            task: $task->fresh(),
            newStatus: TaskStatus::IN_PROGRESS,
            actor: $worker,
        );
    }

    public function test_cancelled_task_can_be_reopened_to_created(): void
    {
        $leader = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $leader);
        $this->addStaffToGroup($group, $worker);

        $task = $this->createDirectTask(
            $leader,
            $group,
            $worker,
        );

        $task->update([
            'status' => TaskStatus::CANCELLED,
        ]);

        $task = $this->service->changeStatus(
            task: $task->fresh(),
            newStatus: TaskStatus::CREATED,
            actor: $leader,
        );

        $this->assertSame(
            TaskStatus::CREATED,
            $task->status,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Metadata / source / deadline
    |--------------------------------------------------------------------------
    */

    public function test_task_can_store_source_and_ai_metadata(): void
    {
        $actor = $this->createStaff('Leader');
        $worker = $this->createStaff('Worker');
        $group = $this->createGroup();

        $this->addStaffToGroup($group, $actor);
        $this->addStaffToGroup($group, $worker);

        $task = $this->service->create(
            [
                'title' => 'AI generated task',
                'description' => 'Generated from Telegram voice message.',
                'group_id' => $group->id,
                'assignee_id' => $worker->id,
                'assignment_type' => TaskAssignmentType::DIRECT,

                'source_type' => 'telegram',
                'source_id' => '123456',
                'source_message_id' => '789',
                'source_url' => 'https://t.me/example/789',

                'ajralish_aniqligi' => 95.50,

                'metadata' => [
                    'ai_model' => 'gemini',
                    'ai_confidence' => 0.955,
                ],
            ],
            $actor,
        );

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'source_type' => 'telegram',
            'source_id' => '123456',
            'source_message_id' => '789',
            'ajralish_aniqligi' => 95.50,
        ]);

        $this->assertSame(
            'gemini',
            $task->fresh()->metadata['ai_model'],
        );
    }
}
