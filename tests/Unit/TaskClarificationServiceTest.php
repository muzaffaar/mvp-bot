<?php

namespace Tests\Unit\AI;

use App\AI\Services\Clarification\TaskClarificationService;
use App\AI\Services\ConversationService;
use App\AI\Services\EntityResolver;
use App\Models\Group;
use App\Models\Staff;
use App\Models\TelegramConversation;
use App\Telegram\Enums\ConversationState;
use App\Telegram\Services\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TaskClarificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskClarificationService $service;

    private TelegramClient $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram = Mockery::mock(
            TelegramClient::class
        );

        $this->service = new TaskClarificationService(
            entityResolver: app(EntityResolver::class),
            conversationService: app(ConversationService::class),
            telegram: $this->telegram,
        );
    }

    public function test_handle_group_selects_existing_group(): void
    {
        $staff = Staff::factory()->create();

        $group = Group::factory()->create([
            'name' => 'IT',
        ]);

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::AWAITING_GROUP->value,
            'context' => [
                'title' => 'Serverni tekshirish',
            ],
        ]);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once();

        $this->service->handleGroup(
            staff: $staff,
            chatId: 12345,
            conversation: $conversation,
            text: 'IT',
        );

        $conversation->refresh();

        $this->assertSame(
            ConversationState::AWAITING_ASSIGNEE->value,
            $conversation->state
        );

        $this->assertSame(
            $group->id,
            $conversation->context['group_id']
        );

        $this->assertSame(
            'IT',
            $conversation->context['group_name']
        );

        $this->assertSame(
            'Serverni tekshirish',
            $conversation->context['title']
        );
    }

    public function test_handle_group_unknown_group_keeps_conversation_state(): void
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
                        'topa olmadim'
                    );
            });

        $this->service->handleGroup(
            staff: $staff,
            chatId: 12345,
            conversation: $conversation,
            text: 'Unknown Group',
        );

        $conversation->refresh();

        $this->assertSame(
            ConversationState::AWAITING_GROUP->value,
            $conversation->state
        );

        $this->assertSame(
            'Serverni tekshirish',
            $conversation->context['title']
        );

        $this->assertArrayNotHasKey(
            'group_id',
            $conversation->context
        );
    }

    public function test_handle_assignee_accepts_group_assignment(): void
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
                'group_id' => $group->id,
                'group_name' => $group->name,
            ],
        ]);

        $result = $this->service->handleAssignee(
            staff: $staff,
            chatId: 12345,
            conversation: $conversation,
            text: 'hammaga',
        );

        $this->assertNotNull($result);

        $this->assertSame(
            'group',
            $result['assignment_type']
        );

        $this->assertNull(
            $result['assignee_id']
        );

        $this->assertNull(
            $result['assignee_name']
        );

        $this->assertSame(
            $group->id,
            $result['group_id']
        );
    }

    public function test_handle_assignee_accepts_all_group_assignment_aliases(): void
    {
        $aliases = [
            'hamma',
            'hammaga',
            'barchaga',
            'barcha',
            'hammasiga',
            'guruhga',
            'guruhdagilarga',
            'all',
        ];

        foreach ($aliases as $alias) {
            $staff = Staff::factory()->create();

            $group = Group::factory()->create();

            $conversation = TelegramConversation::factory()->create([
                'staff_id' => $staff->id,
                'telegram_chat_id' => random_int(
                    100000,
                    999999
                ),
                'state' => ConversationState::AWAITING_ASSIGNEE->value,
                'context' => [
                    'group_id' => $group->id,
                ],
            ]);

            $result = $this->service->handleAssignee(
                staff: $staff,
                chatId: $conversation->telegram_chat_id,
                conversation: $conversation,
                text: $alias,
            );

            $this->assertSame(
                'group',
                $result['assignment_type'],
                "Alias [$alias] should create group assignment."
            );
        }
    }

    public function test_handle_assignee_resolves_staff_from_group(): void
    {
        $staff = Staff::factory()->create();

        $group = Group::factory()->create();

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

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::AWAITING_ASSIGNEE->value,
            'context' => [
                'title' => 'Serverni tekshirish',
                'group_id' => $group->id,
                'group_name' => $group->name,
            ],
        ]);

        $result = $this->service->handleAssignee(
            staff: $staff,
            chatId: 12345,
            conversation: $conversation,
            text: 'Ali Valiyev',
        );

        $this->assertNotNull($result);

        $this->assertSame(
            'direct',
            $result['assignment_type']
        );

        $this->assertSame(
            $assignee->id,
            $result['assignee_id']
        );

        $this->assertSame(
            'Ali Valiyev',
            $result['assignee_name']
        );

        $this->assertSame(
            $group->id,
            $result['group_id']
        );
    }

    public function test_handle_assignee_rejects_staff_outside_group(): void
    {
        $staff = Staff::factory()->create();

        $group = Group::factory()->create();

        $outsideStaff = Staff::factory()->create([
            'full_name' => 'Outside Staff',
            'status' => 'active',
        ]);

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::AWAITING_ASSIGNEE->value,
            'context' => [
                'group_id' => $group->id,
            ],
        ]);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once();

        $result = $this->service->handleAssignee(
            staff: $staff,
            chatId: 12345,
            conversation: $conversation,
            text: 'Outside Staff',
        );

        $this->assertNull($result);
    }

    public function test_handle_assignee_rejects_inactive_group_membership(): void
    {
        $staff = Staff::factory()->create();

        $group = Group::factory()->create();

        $assignee = Staff::factory()->create([
            'full_name' => 'Inactive Member',
            'status' => 'active',
        ]);

        $group->staff()->attach(
            $assignee->id,
            [
                'status' => 'inactive',
                'joined_at' => now(),
            ]
        );

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::AWAITING_ASSIGNEE->value,
            'context' => [
                'group_id' => $group->id,
            ],
        ]);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once();

        $result = $this->service->handleAssignee(
            staff: $staff,
            chatId: 12345,
            conversation: $conversation,
            text: 'Inactive Member',
        );

        $this->assertNull($result);
    }

    public function test_handle_assignee_resets_when_group_id_is_missing(): void
    {
        $staff = Staff::factory()->create();

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::AWAITING_ASSIGNEE->value,
            'context' => [
                'title' => 'Serverni tekshirish',
            ],
        ]);

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once();

        $result = $this->service->handleAssignee(
            staff: $staff,
            chatId: 12345,
            conversation: $conversation,
            text: 'Ali',
        );

        $this->assertNull($result);

        $conversation->refresh();

        $this->assertSame(
            ConversationState::IDLE->value,
            $conversation->state
        );

        $this->assertSame(
            [],
            $conversation->context
        );
    }

    public function test_handle_assignee_resets_when_group_no_longer_exists(): void
    {
        $staff = Staff::factory()->create();

        $group = Group::factory()->create();

        $conversation = TelegramConversation::factory()->create([
            'staff_id' => $staff->id,
            'telegram_chat_id' => 12345,
            'state' => ConversationState::AWAITING_ASSIGNEE->value,
            'context' => [
                'group_id' => $group->id,
            ],
        ]);

        $group->delete();

        $this->telegram
            ->shouldReceive('sendMessage')
            ->once();

        $result = $this->service->handleAssignee(
            staff: $staff,
            chatId: 12345,
            conversation: $conversation,
            text: 'Ali',
        );

        $this->assertNull($result);

        $conversation->refresh();

        $this->assertSame(
            ConversationState::IDLE->value,
            $conversation->state
        );

        $this->assertSame(
            [],
            $conversation->context
        );
    }
}
