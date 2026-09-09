<?php

namespace App\Telegram\Handlers;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Services\Authentication\QrLoginService;
use App\Telegram\Services\TelegramStaffResolver;
use App\Telegram\Callbacks\CreateTaskCallback;
use App\Telegram\Services\TelegramClient;
use App\Telegram\Callbacks\TaskStatusCallback;
use App\Telegram\Callbacks\AssigneeSelectionCallback;
use App\Telegram\Callbacks\TaskPostponeCallback;
use App\Telegram\Callbacks\TaskManagementCallback;
use Illuminate\Support\Facades\Log;
use Throwable;

class CallbackQueryHandler
{
    public function __construct(
        private readonly TelegramStaffResolver $staffResolver,
        private readonly AssigneeSelectionCallback $assigneeSelection,
        private readonly CreateTaskCallback $createTask,
        private readonly TelegramClient $telegram,
        private readonly TaskStatusCallback $taskStatus,
        private readonly QrLoginService $qrLoginService,
        private readonly TaskPostponeCallback $taskPostpone,
        private readonly TaskManagementCallback $taskManagement,
    ) {
    }

    public function handle(array $callback): void
    {
        $message = $callback['message'] ?? null;

        if (! $message) {
            return;
        }

        $data = (string) ($callback['data'] ?? '');

        /*
        * QR login callbacks don't require normal command
        * staff resolution. The QR session itself identifies
        * the staff.
        */
        if (
            str_starts_with($data, 'qr_approve:')
            || str_starts_with($data, 'qr_cancel:')
        ) {
            $this->handleQrCallback($callback);

            return;
        }

        /*
        * Existing task callbacks
        */
        $staff = $this->staffResolver->resolve([
            'from' => $callback['from'] ?? [],
        ]);

        $chatId = (int) ($message['chat']['id'] ?? 0);

        // Task management is private-chat only. A callback can be forged or an
        // old management card could theoretically exist in a group, so protect
        // the callback pipeline as well as the /manage command.
        if (
            str_starts_with($data, 'tm:')
            && (($message['chat']['type'] ?? 'private') !== 'private')
        ) {
            $this->telegram->answerCallbackQuery(
                $callback['id'],
                '🔒 Vazifalarni boshqarish faqat shaxsiy chatda ishlaydi.'
            );

            return;
        }

        // Task management callbacks are handled by their dedicated callback
        // class. Route every tm:* callback before the legacy callback branches.
        if (str_starts_with($data, 'tm:')) {
            $this->taskManagement->handle(
                staff: $staff,
                data: $data,
                callbackId: (string) $callback['id'],
                chatId: $chatId,
                messageId: (int) $message['message_id'],
            );

            return;
        }

        if (preg_match('/^postpone:task:(\d+)$/', $data, $matches) === 1) {
            $this->taskPostpone->selectTask(
                staff: $staff,
                taskId: (int) $matches[1],
                callbackQueryId: $callback['id'],
                chatId: $chatId,
                messageId: (int) $message['message_id'],
            );

            return;
        }

        if (
            preg_match(
                '/^postpone:duration:(\d+):(\d+)$/',
                $data,
                $matches
            ) === 1
        ) {
            $this->taskPostpone->request(
                staff: $staff,
                taskId: (int) $matches[1],
                minutes: (int) $matches[2],
                callbackQueryId: $callback['id'],
                chatId: $chatId,
                messageId: (int) $message['message_id'],
            );

            return;
        }

        if (
            preg_match(
                '/^postpone:(approve|reject):(\d+)$/',
                $data,
                $matches
            ) === 1
        ) {
            $this->taskPostpone->decide(
                staff: $staff,
                requestId: (int) $matches[2],
                approved: $matches[1] === 'approve',
                callbackQueryId: $callback['id'],
                chatId: $chatId,
                messageId: (int) $message['message_id'],
            );

            return;
        }

        if (
            preg_match(
                '/^task:assignee:(\d+)$/',
                $data,
                $matches,
            ) === 1
        ) {
            $this->assigneeSelection->handle(
                staff: $staff,
                chatId: $chatId,
                messageId: (int) $message['message_id'],
                callbackQueryId: $callback['id'],
                assigneeId: (int) $matches[1],
            );

            return;
        }

        if ($data === 'task:create:confirm') {
            $this->createTask->confirm(
                staff: $staff,
                chatId: $chatId,
                messageId: (int) $message['message_id'],
                callbackQueryId: $callback['id'],
            );

            return;
        }

        if ($data === 'task:create:cancel') {
            $this->createTask->cancel(
                staff: $staff,
                chatId: $chatId,
                messageId: (int) $message['message_id'],
                callbackQueryId: $callback['id'],
            );

            return;
        }

        if (
            preg_match(
                '/^task:status:(\d+):(accepted|in_progress|awaiting_acceptance|closed|completion_approved)$/',
                $data,
                $matches
            )
        ) {
            $this->taskStatus->handle(
                staff: $staff,
                taskId: (int) $matches[1],
                newStatus: TaskStatus::from($matches[2]),
                callbackQueryId: $callback['id'],
                chatId: (int) $callback['message']['chat']['id'],
                messageId: (int) $message['message_id'],
            );

            return;
        }

        $this->telegram->answerCallbackQuery(
            $callback['id'],
            'Nomaʼlum amal.'
        );
    }

    private function handleQrCallback(array $callback): void
    {
        $callbackId = $callback['id'] ?? null;
        $data = (string) ($callback['data'] ?? '');
        $message = $callback['message'] ?? null;

        if (! $callbackId || ! $message) {
            return;
        }

        /*
         * Telegram user who clicked the button.
         */
        $fromUserId = (string) (
            $callback['from']['id'] ?? ''
        );

        /*
         * Telegram chat where the button/message exists.
         *
         * For a private bot chat this should normally be
         * the same Telegram ID as the user.
         */
        $chatId = (string) (
            $message['chat']['id'] ?? ''
        );

        /*
         * Exact Telegram message containing the pressed button.
         */
        $messageId = (int) (
            $message['message_id'] ?? 0
        );

        if ($fromUserId === '' || $chatId === '') {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                'Telegram foydalanuvchisi aniqlanmadi.'
            );

            return;
        }

        /*
         * Determine action + session ID.
         */
        if (
            preg_match(
                '/^qr_(approve|cancel):(\d+)$/',
                $data,
                $matches
            ) !== 1
        ) {
            return;
        }

        $action = $matches[1];
        $sessionId = (int) $matches[2];

        /*
         * Find session.
         */
        $session = \App\Models\QrLoginSession::query()
            ->whereKey($sessionId)
            ->with('staff')
            ->first();

        if (! $session) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                'Login sessiyasi topilmadi.'
            );

            return;
        }

        /*
         * Expiration.
         */
        if ($session->isExpired()) {
            $this->qrLoginService->expire($session);

            $this->telegram->answerCallbackQuery(
                $callbackId,
                'QR kod muddati tugagan.'
            );

            return;
        }

        /*
         * Staff must exist.
         */
        if (! $session->staff) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                'Staff topilmadi.'
            );

            return;
        }

        /*
         * SECURITY CHECK
         *
         * The QR session belongs to the Telegram account
         * that opened the bot chat.
         */
        $staffTelegramChatId = (string) (
            $session->staff->telegram_chat_id ?? ''
        );

        if (
            $staffTelegramChatId === ''
            || $staffTelegramChatId !== $chatId
            || $staffTelegramChatId !== $fromUserId
        ) {
            Log::warning('QR login ownership mismatch', [
                'session_id' => $session->id,
                'staff_id' => $session->staff->id,
                'staff_telegram_chat_id' => $staffTelegramChatId,
                'callback_from_id' => $fromUserId,
                'callback_message_chat_id' => $chatId,
                'callback_data' => $data,
            ]);

            $this->telegram->answerCallbackQuery(
                $callbackId,
                'Bu login so‘rovi sizga tegishli emas.'
            );

            return;
        }

        /*
         * Cancel.
         */
        if ($action === 'cancel') {
            $cancelled = $this->qrLoginService->cancel(
                $session
            );

            if ($cancelled && $messageId > 0) {
                try {
                    $this->telegram->deleteMessage(
                        chatId: $chatId,
                        messageId: $messageId,
                    );
                } catch (Throwable $e) {
                    Log::warning(
                        'Failed to delete QR login request message.',
                        [
                            'session_id' => $session->id,
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ],
                    );
                }
            }

            $this->telegram->answerCallbackQuery(
                $callbackId,
                $cancelled
                    ? 'Login bekor qilindi.'
                    : 'Login sessiyasi allaqachon yakunlangan.'
            );

            return;
        }

        /*
         * Approve.
         */
        if ($action === 'approve') {
            if (! $session->isPending()) {
                $this->telegram->answerCallbackQuery(
                    $callbackId,
                    'Login sessiyasi allaqachon yakunlangan.'
                );

                return;
            }

            $approved = $this->qrLoginService->approve(
                $session
            );

            if (! $approved) {
                $this->telegram->answerCallbackQuery(
                    $callbackId,
                    'Login sessiyasini tasdiqlab bo‘lmadi.'
                );

                return;
            }

            if ($messageId > 0) {
                try {
                    $this->telegram->deleteMessage(
                        chatId: $chatId,
                        messageId: $messageId,
                    );
                } catch (Throwable $e) {
                    Log::warning(
                        'Failed to delete QR login request message.',
                        [
                            'session_id' => $session->id,
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ],
                    );
                }
            }

            $this->telegram->answerCallbackQuery(
                $callbackId,
                '✅ Login tasdiqlandi.'
            );
        }
    }
}
