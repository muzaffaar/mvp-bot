<?php

namespace Tests\Unit\AI;

use App\AI\Services\ConversationService;
use App\Models\Staff;
use App\Models\TelegramConversation;
use App\Telegram\Enums\ConversationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConversationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ConversationService::class);
    }

    public function test_get_or_create_creates_new_conversation(): void
    {
        $staff = Staff::factory()->create();
        $chatId = 123456789;

        $conversation = $this->service->getOrCreate(
            staff: $staff,
            chatId: $chatId,
        );

        $this->assertInstanceOf(
            TelegramConversation::class,
            $conversation
        );

        $this->assertDatabaseHas('telegram_conversations', [
            'id' => $conversation->id,
            'staff_id' => $staff->id,
            'telegram_chat_id' => $chatId,
            'state' => ConversationState::IDLE->value,
        ]);

        $this->assertSame([], $conversation->context);
        $this->assertNotNull($conversation->last_activity_at);
    }

    public function test_get_or_create_returns_existing_conversation(): void
    {
        $staff = Staff::factory()->create();

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 123456789,
            'state' => ConversationState::AWAITING_GROUP->value,
            'context' => [
                'title' => 'Test task',
            ],
        ]);

        $result = $this->service->getOrCreate(
            staff: $staff,
            chatId: 123456789,
        );

        $this->assertSame(
            $conversation->id,
            $result->id
        );

        $this->assertSame(
            1,
            TelegramConversation::query()->count()
        );

        $this->assertSame(
            ConversationState::AWAITING_GROUP,
            $this->service->state($result)
        );

        $this->assertSame(
            ['title' => 'Test task'],
            $this->service->context($result)
        );
    }

    public function test_state_returns_conversation_state_enum(): void
    {
        $conversation = TelegramConversation::factory()->create([
            'state' => ConversationState::AWAITING_ASSIGNEE->value,
        ]);

        $state = $this->service->state($conversation);

        $this->assertInstanceOf(
            ConversationState::class,
            $state
        );

        $this->assertSame(
            ConversationState::AWAITING_ASSIGNEE,
            $state
        );
    }

    public function test_context_returns_empty_array_when_context_is_null(): void
    {
        $conversation = TelegramConversation::factory()->create([
            'context' => null,
        ]);

        $this->assertSame(
            [],
            $this->service->context($conversation)
        );
    }

    public function test_update_changes_state_and_context(): void
    {
        $conversation = TelegramConversation::factory()->create([
            'state' => ConversationState::IDLE->value,
            'context' => [],
        ]);

        $context = [
            'title' => 'Serverni tekshirish',
            'group_id' => 10,
        ];

        $this->service->update(
            conversation: $conversation,
            state: ConversationState::AWAITING_ASSIGNEE,
            context: $context,
        );

        $conversation->refresh();

        $this->assertSame(
            ConversationState::AWAITING_ASSIGNEE->value,
            $conversation->state
        );

        $this->assertSame(
            $context,
            $conversation->context
        );

        $this->assertNotNull(
            $conversation->last_activity_at
        );
    }

    public function test_merge_context_preserves_existing_values(): void
    {
        $conversation = TelegramConversation::factory()->create([
            'context' => [
                'title' => 'Serverni tekshirish',
                'priority' => 'normal',
            ],
        ]);

        $result = $this->service->mergeContext(
            conversation: $conversation,
            context: [
                'group_id' => 5,
                'group_name' => 'IT',
            ],
        );

        $this->assertEqualsCanonicalizing([
            'title' => 'Serverni tekshirish',
            'priority' => 'normal',
            'group_id' => 5,
            'group_name' => 'IT',
        ], $result);

        $conversation->refresh();

        $this->assertEqualsCanonicalizing(
            $result,
            $conversation->context
        );
    }

    public function test_merge_context_overwrites_existing_keys(): void
    {
        $conversation = TelegramConversation::factory()->create([
            'context' => [
                'title' => 'Old title',
                'priority' => 'normal',
            ],
        ]);

        $result = $this->service->mergeContext(
            conversation: $conversation,
            context: [
                'title' => 'New title',
                'priority' => 'high',
            ],
        );

        $this->assertSame([
            'title' => 'New title',
            'priority' => 'high',
        ], $result);

        $conversation->refresh();

        $this->assertSame(
            'New title',
            $conversation->context['title']
        );

        $this->assertSame(
            'high',
            $conversation->context['priority']
        );
    }

    public function test_set_state_changes_only_state(): void
    {
        $context = [
            'title' => 'Test task',
            'group_id' => 5,
        ];

        $conversation = TelegramConversation::factory()->create([
            'state' => ConversationState::IDLE->value,
            'context' => $context,
        ]);

        $oldActivity = $conversation->last_activity_at;

        $this->service->setState(
            conversation: $conversation,
            state: ConversationState::AWAITING_GROUP,
        );

        $conversation->refresh();

        $this->assertSame(
            ConversationState::AWAITING_GROUP->value,
            $conversation->state
        );

        $this->assertSame(
            $context,
            $conversation->context
        );

        $this->assertNotNull(
            $conversation->last_activity_at
        );

        $this->assertTrue(
            $conversation->last_activity_at->greaterThanOrEqualTo(
                $oldActivity
            )
        );
    }

    public function test_reset_returns_conversation_to_idle(): void
    {
        $conversation = TelegramConversation::factory()->create([
            'state' => ConversationState::AWAITING_TASK_CONFIRMATION->value,
            'context' => [
                'title' => 'Test task',
                'group_id' => 5,
                'assignment_type' => 'direct',
            ],
        ]);

        $this->service->reset($conversation);

        $conversation->refresh();

        $this->assertSame(
            ConversationState::IDLE->value,
            $conversation->state
        );

        $this->assertSame(
            [],
            $conversation->context
        );

        $this->assertNotNull(
            $conversation->last_activity_at
        );
    }
}
