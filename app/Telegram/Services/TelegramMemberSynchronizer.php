<?php

namespace App\Telegram\Services;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;

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

        /*
         * A staff member who leaves the group is soft-deleted, never hard
         * deleted: their history (authored/assigned tasks, comments, logs)
         * must stay intact. Being trashed already excludes them from every
         * default Staff::query() used to pick assignment candidates, and
         * status/group fields are cleared too as a belt-and-suspenders
         * guard for any code that checks those directly.
         */
        DB::transaction(function () use ($staff): void {
            $staff->syncRoles([]);
            $staff->update([
                'status' => 'inactive',
                'group_chat_id' => null,
                'group_name' => null,
            ]);
            $staff->delete();
        });
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
            // withTrashed(): a staff member who previously left this (or
            // another) group is soft-deleted, not gone. Rejoining must
            // restore the same identity/history rather than create a
            // duplicate record.
            $staff = Staff::withTrashed()
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

            if ($staff->trashed()) {
                $staff->restore();
            }

            $staff->update([
                'status' => 'active',
                'group_chat_id' => $groupChatId,
                'group_name' => $groupName,
                'full_name' => $this->fullName($member),
                'username' => $member['username'] ?? null,
            ]);

            // remove() strips all roles on leave; restore the default role
            // if none are left, without clobbering a role an admin may have
            // set for someone who never actually left.
            if ($staff->roles()->count() === 0) {
                $staff->syncRoles(['executor']);
            }
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
