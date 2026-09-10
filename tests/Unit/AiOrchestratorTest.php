<?php

namespace Tests\Unit\AI;

use App\AI\Gemini\GeminiClient;
use App\AI\Services\AiIntentSchema;
use App\AI\Services\AiOrchestrator;
use App\AI\Services\Clarification\TaskClarificationService;
use App\AI\Services\ConversationService;
use App\AI\Services\EntityResolver;
use App\Enums\TaskAssignmentType;
use App\Enums\TaskPriority;
use App\Models\Group;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TelegramConversation;
use App\Services\Task\TaskService;
use App\Telegram\Enums\ConversationState;
use App\Telegram\Services\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AiOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private GeminiClient $gemini;

    private TelegramClient $telegram;

    private TaskService $taskService;

    private TaskClarificationService $clarificationService;

    private AiOrchestrator $orchestrator;

    private MockInterface $entityResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gemini = Mockery::mock(
            GeminiClient::class
        );

        $this->entityResolver = Mockery::mock(
            EntityResolver::class
        );

        $this->telegram = Mockery::mock(
            TelegramClient::class
        );

        $this->taskService = Mockery::mock(
            TaskService::class
        );

        $this->clarificationService = Mockery::mock(
            TaskClarificationService::class
        );

        $this->orchestrator = new AiOrchestrator(
            gemini: $this->gemini,
            conversationService: app(ConversationService::class),
            entityResolver: $this->entityResolver,
            telegram: $this->telegram,
            taskService: $this->taskService,
            clarificationService: $this->clarificationService,
        );

    }

    public function test_new_text_request_is_sent_to_gemini(): void
    {
        $staff = Staff::factory()->create();

        $this->gemini
            ->shouldReceive('interpret')
            ->once()
            ->withArgs(function (
                string $systemInstruction,
                string $input,
                array $schema
            ) {
                return is_string($systemInstruction)
                    && $systemInstruction !== ''
                    && str_contains(
                        $input,
                        'Serverni tekshirish'
                    )
                    && $schema === AiIntentSchema::createTask();
            })
            ->andReturn([
                'intent' => 'unknown',
            ]);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (
                $chatId,
                $text
            ) use ($staff) {
                return $chatId === 12345
                    && str_contains(
                        $text,
                        'So‘rovni tushunmadim'
                    );
            });

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'message_id' => 100,
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'Serverni tekshirish',
        );
    }

    public function test_unknown_intent_does_not_create_task(): void
    {
        $staff = Staff::factory()->create();

        $this->gemini
            ->shouldReceive('interpret')
            ->once()
            ->andReturn([
                'intent' => 'change_status',
            ]);

        $this->taskService
            ->shouldNotReceive('create');

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once();

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'Vazifaning holatini o‘zgartir',
        );
    }

    public function test_missing_group_moves_conversation_to_awaiting_group(): void
    {
        $staff = Staff::factory()->create();

        $this->gemini
            ->shouldReceive('interpret')
            ->once()
            ->andReturn([
                'intent' => 'create_task',
                'title' => 'Serverni tekshirish',
                'description' => 'Server holatini tekshirish',
                'group_name' => 'Unknown Group',
                'assignment_type' => 'direct',
                'assignee_name' => 'Ali',
                'priority' => 'normal',
            ]);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (
                $chatId,
                $text
            ) {
                return $chatId === 12345
                    && str_contains(
                        $text,
                        'Qaysi guruhga yuboray?'
                    );
            });

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'message_id' => 101,
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'Serverni tekshirish',
        );

        $conversation = TelegramConversation::query()
            ->where('telegram_chat_id', 12345)
            ->firstOrFail();

        $this->assertSame(
            ConversationState::AWAITING_GROUP->value,
            $conversation->state
        );

        $this->assertSame(
            'Serverni tekshirish',
            $conversation->context['title']
        );
    }

    public function test_missing_assignee_moves_conversation_to_awaiting_assignee(): void
    {
        $staff = Staff::factory()->create();

        $group = Group::factory()->create([
            'name' => 'IT',
        ]);

        $this->gemini
            ->shouldReceive('interpret')
            ->once()
            ->andReturn([
                'intent' => 'create_task',
                'title' => 'Serverni tekshirish',
                'description' => 'Server holatini tekshirish',
                'group_name' => 'IT',
                'assignment_type' => null,
                'assignee_name' => null,
                'priority' => 'normal',
            ]);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (
                $chatId,
                $text
            ) use ($group) {
                return $chatId === 12345
                    && str_contains(
                        $text,
                        $group->name
                    )
                    && str_contains(
                        $text,
                        'Kimga biriktiray'
                    );
            });

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'message_id' => 102,
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'IT guruhiga serverni tekshirish',
        );

        $conversation = TelegramConversation::query()
            ->where('telegram_chat_id', 12345)
            ->firstOrFail();

        $this->assertSame(
            ConversationState::AWAITING_ASSIGNEE->value,
            $conversation->state
        );

        $this->assertSame(
            $group->id,
            $conversation->context['group_id']
        );
    }

    public function test_valid_direct_task_is_created(): void
    {
        $staff = Staff::factory()->create();

        $group = Group::factory()->create([
            'name' => 'IT',
        ]);

        $assignee = Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'status' => 'active',
        ]);

        $group->staff()->attach(
            $assignee->id,
            [
                'status' => 'active',
                'joined_at' => now(),
            ]
        );

        $task = Task::factory()->make([
            'id' => 1,
            'title' => 'Serverni tekshirish',
            'group_id' => $group->id,
            'assignee_id' => $assignee->id,
            'assignment_type' => TaskAssignmentType::DIRECT,
            'priority' => TaskPriority::NORMAL,
        ]);

        $task->setRelation('group', $group);
        $task->setRelation('assignee', $assignee);

        $this->gemini
            ->shouldReceive('interpret')
            ->once()
            ->andReturn([
                'intent' => 'create_task',
                'title' => 'Serverni tekshirish',
                'description' => 'Server holatini tekshirish',
                'group_name' => 'IT',
                'assignee_name' => 'Ali Valiyev',
                'assignment_type' => 'direct',
                'priority' => 'normal',
                'deadline' => null,
            ]);

        $this->entityResolver
    ->shouldReceive('resolveGroup')
    ->once()
    ->with('IT')
    ->andReturn($group);

$this->entityResolver
    ->shouldReceive('resolveStaff')
    ->once()
    ->withArgs(function ($name, $resolvedGroup) use ($group) {
        dump([
            'resolveStaff_name' => $name,
            'resolveStaff_group_id' => $resolvedGroup?->id,
            'expected_group_id' => $group->id,
        ]);

        return $name === 'Ali Valiyev'
            && $resolvedGroup->is($group);
    })
    ->andReturn($assignee);

        $this->taskService
    ->shouldReceive('create')
    ->once()
    ->withArgs(function (
        array $data,
        Staff $actor
    ) use ($staff, $group, $assignee) {

        dump([
            'data' => $data,
            'actor_id' => $actor->id,
            'expected_actor_id' => $staff->id,
        ]);

        return
            $data['title'] === 'Serverni tekshirish'
            && $data['description'] === 'Server holatini tekshirish'
            && $data['group_id'] === $group->id
            && $data['assignee_id'] === $assignee->id
            && $data['assignment_type'] === 'direct'
            && $data['priority'] === 'normal'
            && $data['deadline'] === null
            && $data['source_type'] === 'telegram'
            && $data['source_message_id'] === '103'
            && $actor->is($staff);
    })
    ->andReturn($task);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (
                $chatId,
                $text
            ) {
                return $chatId === 12345
                    && str_contains(
                        $text,
                        'Vazifa yaratildi'
                    )
                    && str_contains(
                        $text,
                        'Serverni tekshirish'
                    );
            });

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'message_id' => 103,
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'IT guruhidagi Ali ga serverni tekshirish vazifasini ber',
        );

        $conversation = TelegramConversation::query()
            ->where('telegram_chat_id', 12345)
            ->firstOrFail();

        $this->assertSame(
            ConversationState::IDLE->value,
            $conversation->state
        );

        $this->assertSame(
            [],
            $conversation->context
        );
    }

    public function test_valid_group_task_is_created_without_assignee(): void
    {
        $staff = Staff::factory()->create();

        $group = Group::factory()->create([
            'name' => 'IT',
        ]);

        $task = Task::factory()->make([
            'id' => 2,
            'title' => 'Server monitoring',
            'group_id' => $group->id,
            'assignee_id' => null,
            'assignment_type' => TaskAssignmentType::GROUP,
        ]);

        $task->setRelation('group', $group);
        $task->setRelation('assignee', null);

        $this->gemini
            ->shouldReceive('interpret')
            ->once()
            ->andReturn([
                'intent' => 'create_task',
                'title' => 'Server monitoring',
                'description' => null,
                'group_name' => 'IT',
                'assignment_type' => 'group',
                'assignee_name' => null,
                'priority' => 'normal',
                'deadline' => null,
            ]);

        $this->entityResolver
            ->shouldReceive('resolveGroup')
            ->once()
            ->with('IT')
            ->andReturn($group);

        $this->taskService
            ->shouldReceive('create')
            ->once()
            ->withArgs(function (
                array $data,
                Staff $actor
            ) use ($group, $staff) {
                return $data['title'] === 'Server monitoring'
                    && $data['group_id'] === $group->id
                    && $data['assignee_id'] === null
                    && $data['assignment_type'] === 'group'
                    && $data['priority'] === 'normal'
                    && $data['source_type'] === 'telegram'
                    && $data['source_message_id'] === '104'
                    && $actor->is($staff);
            })
            ->andReturn($task);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once();

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'message_id' => 104,
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'IT guruhiga server monitoring vazifasini och',
        );
    }

    public function test_awaiting_group_answer_does_not_call_gemini(): void
    {
        $staff = Staff::factory()->create();

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::AWAITING_GROUP->value,
            'context' => [
                'title' => 'Serverni tekshirish',
            ],
        ]);

        $group = Group::factory()->create([
            'name' => 'IT',
        ]);

        $this->gemini
            ->shouldNotReceive('interpret');

        $this->clarificationService
            ->shouldReceive('handleGroup')
            ->once()
            ->withArgs(function (
                Staff $resolvedStaff,
                int $chatId,
                TelegramConversation $resolvedConversation,
                string $text
            ) use ($staff, $conversation) {
                return $resolvedStaff->is($staff)
                    && $chatId === 12345
                    && $resolvedConversation->is($conversation)
                    && $text === 'IT';
            });

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'IT',
        );
    }

    public function test_awaiting_assignee_answer_updates_state_and_sends_confirmation(): void
    {
        $staff = Staff::factory()->create();

        $group = Group::factory()->create([
            'name' => 'IT',
        ]);

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::AWAITING_ASSIGNEE->value,
            'context' => [
                'title' => 'Serverni tekshirish',
                'description' => 'Server holatini tekshirish',
                'group_id' => $group->id,
                'group_name' => 'IT',
                'priority' => 'normal',
            ],
        ]);

        $context = [
            'title' => 'Serverni tekshirish',
            'description' => 'Server holatini tekshirish',
            'group_id' => $group->id,
            'group_name' => 'IT',
            'assignment_type' => 'direct',
            'assignee_id' => 99,
            'assignee_name' => 'Ali Valiyev',
            'priority' => 'normal',
        ];

        $this->gemini
            ->shouldNotReceive('interpret');

        $this->clarificationService
            ->shouldReceive('handleAssignee')
            ->once()
            ->andReturn($context);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (
                $chatId,
                $text,
                $replyMarkup = null,
                $parseMode = null
            ) {
                return $chatId === 12345
                    && str_contains(
                        $text,
                        'Vazifani tasdiqlash'
                    )
                    && str_contains(
                        $text,
                        'Serverni tekshirish'
                    )
                    && str_contains(
                        $text,
                        'Ali Valiyev'
                    )
                    && $parseMode === 'HTML'
                    && isset(
                        $replyMarkup['inline_keyboard']
                    );
            });

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'Ali Valiyev',
        );

        $conversation->refresh();

        $this->assertSame(
            ConversationState::AWAITING_TASK_CONFIRMATION->value,
            $conversation->state
        );

        $this->assertSame(
            'Ali Valiyev',
            $conversation->context['assignee_name']
        );
    }

    public function test_invalid_assignee_answer_does_not_change_state(): void
    {
        $staff = Staff::factory()->create();

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::AWAITING_ASSIGNEE->value,
            'context' => [
                'title' => 'Serverni tekshirish',
                'group_id' => 1,
            ],
        ]);

        $this->gemini
            ->shouldNotReceive('interpret');

        $this->clarificationService
            ->shouldReceive('handleAssignee')
            ->once()
            ->andReturn(null);

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'Unknown Staff',
        );

        $conversation->refresh();

        $this->assertSame(
            ConversationState::AWAITING_ASSIGNEE->value,
            $conversation->state
        );
    }

    public function test_gemini_exception_sends_error_message(): void
    {
        $staff = Staff::factory()->create();

        $this->gemini
            ->shouldReceive('interpret')
            ->once()
            ->andThrow(
                new \RuntimeException('Gemini unavailable')
            );

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (
                $chatId,
                $text,
                $replyMarkup = null,
                $parseMode = null
            ) {
                return $chatId === 12345
                    && str_contains(
                        $text,
                        'xatolik yuz berdi'
                    )
                    && $parseMode === 'HTML';
            });

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'Serverni tekshir',
        );
    }

    public function test_conversation_context_is_preserved_when_gemini_returns_new_data(): void
    {
        $staff = Staff::factory()->create();

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::IDLE->value,
            'context' => [
                'existing_key' => 'existing-value',
            ],
        ]);

        $this->gemini
            ->shouldReceive('interpret')
            ->once()
            ->andReturn([
                'intent' => 'create_task',
                'title' => 'New task',
                'group_name' => 'Unknown',
                'priority' => 'high',
            ]);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once();

        $this->orchestrator->processText(
            staff: $staff,
            message: [
                'message_id' => 500,
                'chat' => [
                    'id' => 12345,
                ],
            ],
            text: 'New task',
        );

        $conversation->refresh();

        $this->assertSame(
            'existing-value',
            $conversation->context['existing_key']
        );

        $this->assertSame(
            'New task',
            $conversation->context['title']
        );

        $this->assertSame(
            500,
            $conversation->context['message_id']
        );
    }
}
