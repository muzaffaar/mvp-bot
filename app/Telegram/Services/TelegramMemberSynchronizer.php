<?php

namespace App\Telegram\Services;

use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramMemberSynchronizer
{
    public function sync(array $message): void
    {
        if (! config('services.group.sync_enabled')) {
            return;
        }

        $chat = $message['chat'] ?? null;
        $members = $message['new_chat_members'] ?? [];

        if (! $chat || ! $members) {
            return;
        }

        $groupChatId = $chat['id'] ?? null;
        $groupName = $this->groupName($chat);

        if (! $groupChatId) {
            return;
        }

        foreach ($members as $member) {
            $this->syncMember(
                member: $member,
                groupChatId: $groupChatId,
                groupName: $groupName,
            );
        }
    }

    /**
     * Synchronize a Telegram service message that indicates a member left
     * or was removed from the group.
     *
     * We only remove/deactivate a staff record when that record belongs to
     * the same synchronized Telegram group. This prevents a service message
     * from another chat from deleting an unrelated staff account.
     */
    public function remove(array $message): void
    {
        if (! config('services.group.sync_enabled')) {
            return;
        }

        $chat = $message['chat'] ?? null;
        $member = $message['left_chat_member'] ?? null;

        if (! is_array($chat) || ! is_array($member)) {
            return;
        }

        $telegramUserId = $member['id'] ?? null;
        $groupChatId = $chat['id'] ?? null;

        if (! $telegramUserId || ! $groupChatId) {
            return;
        }

        if (($member['is_bot'] ?? false) === true) {
            return;
        }

        $staff = Staff::query()
            ->where('telegram_chat_id', (string) $telegramUserId)
            ->where('group_chat_id', (string) $groupChatId)
            ->first();

        if (! $staff) {
            return;
        }

        try {
            DB::transaction(function () use ($staff): void {
                // Remove role assignments explicitly; Spatie pivot rows do not
                // necessarily have a database FK that cascades from staff.
                $staff->syncRoles([]);
                $staff->delete();
            });
        } catch (QueryException $exception) {
            /*
             * Some long-lived business records intentionally use RESTRICT
             * foreign keys (for example authored tasks/comments). In that case
             * a hard delete would fail and repeatedly crash the Telegram queue.
             * Keep historical data intact while immediately removing the person
             * from the active synchronized group/staff pool.
             */
            Log::warning('Telegram member could not be hard-deleted; deactivating staff instead.', [
                'staff_id' => $staff->id,
                'telegram_user_id' => $telegramUserId,
                'group_chat_id' => $groupChatId,
                'exception' => $exception->getMessage(),
            ]);

            $staff->syncRoles([]);
            $staff->update([
                'status' => 'inactive',
                'group_chat_id' => null,
                'group_name' => null,
            ]);
        }
    }

    /**
     * Handle a chat_member update as an additional safety net. Telegram may
     * report removals through chat_member rather than a message service event.
     */
    public function syncChatMemberUpdate(array $chatMember): void
    {
        if (! config('services.group.sync_enabled')) {
            return;
        }

        $chat = $chatMember['chat'] ?? null;
        $newChatMember = $chatMember['new_chat_member'] ?? null;

        if (! is_array($chat) || ! is_array($newChatMember)) {
            return;
        }

        $status = $newChatMember['status'] ?? null;
        $user = $newChatMember['user'] ?? null;

        if (! in_array($status, ['left', 'kicked'], true) || ! is_array($user)) {
            return;
        }

        $this->remove([
            'chat' => $chat,
            'left_chat_member' => $user,
        ]);
    }

    private function syncMember(
        array $member,
        int|string $groupChatId,
        ?string $groupName,
    ): void {
        $telegramUserId = $member['id'] ?? null;

        if (! $telegramUserId) {
            return;
        }

        // Don't create application users for Telegram bots.
        if (($member['is_bot'] ?? false) === true) {
            return;
        }

        DB::transaction(function () use (
            $member,
            $telegramUserId,
            $groupChatId,
            $groupName
        ): void {
            $staff = Staff::query()
                ->where('telegram_chat_id', $telegramUserId)
                ->first();

            if (! $staff) {
                $staff = Staff::query()->create([
                    'telegram_chat_id' => $telegramUserId,
                    'group_chat_id' => $groupChatId,
                    'group_name' => $groupName,
                    'full_name' => $this->fullName($member),
                    'username' => $member['username'] ?? null,
                ]);

                $staff->syncRoles(['executor']);

                return;
            }

            $staff->update([
                'status' => 'active',
                'group_chat_id' => $groupChatId,
                'group_name' => $groupName,
                'full_name' => $this->fullName($member),
                'username' => $member['username'] ?? null,
            ]);
        });
    }

    private function fullName(array $member): string
    {
        return trim(implode(' ', array_filter([
            $member['first_name'] ?? null,
            $member['last_name'] ?? null,
        ])));
    }

    private function groupName(array $chat): ?string
    {
        return $chat['title']
            ?? $chat['username']
            ?? null;
    }
}
