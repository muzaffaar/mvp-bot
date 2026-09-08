<?php

namespace App\Telegram\Handlers;

use App\Services\Authentication\QrLoginService;
use App\Services\Staff\StaffService;
use App\Models\Staff;
use App\Telegram\Services\TelegramClient;
use Illuminate\Support\Facades\Log;

class StartCommandHandler
{
    public function __construct(
        private readonly StaffService $staffService,
        private readonly QrLoginService $qrLoginService,
        private readonly TelegramClient $telegram,
    ) {
    }

    public function handle(array $message): void
    {
        // Reply keyboards belong to the actual chat. Never derive the target
        // from `from.id`, otherwise a /start received in another context can
        // send markup to the wrong chat or fail silently.
        $chatId = $message['chat']['id'] ?? null;

        if (! $chatId) {
            return;
        }

        $text = trim(
            (string) ($message['text'] ?? '')
        );

        if (! preg_match(
            '/^\/start(?:@\w+)?(?:\s+(.+))?$/',
            $text,
            $matches
        )) {
            return;
        }

        $token = isset($matches[1])
            ? trim($matches[1])
            : null;

        /*
         * /start without token
         */
        if (! $token) {
            $this->telegram->sendMessage(
                $chatId,
                "Assalomu alaykum.\n\n"
                . "Vazifa boshqaruv tizimiga xush kelibsiz.\n"
                . "Oddiy tilda vazifa yaratishingiz mumkin.\n\n"
                . "Masalan:\n"
                . "“IT guruhidagi Aliga serverni tekshirish vazifasini ber.”",
                $this->mainKeyboard(),
            );

            return;
        }

        $telegramUsername = $message['from']['username'] ?? '';

        /*
         * =========================================================
         * 1. STAFF ACTIVATION
         * =========================================================
         */
        $activation = $this->staffService
            ->activateFromTelegram(
                $token,
                (string) $chatId,
                $telegramUsername
            );

        if ($activation) {
            $this->sendActivationSuccess(
                $chatId,
                $activation
            );

            return;
        }

        /*
         * =========================================================
         * 2. QR LOGIN
         * =========================================================
         */
        $session = $this->qrLoginService
            ->findByToken($token);

        if (! $session) {
            $this->telegram->sendMessage(
                $chatId,
                '❌ Token noto‘g‘ri yoki eskirgan.'
            );

            return;
        }

        /*
         * QR expired
         */
        if ($session->isExpired()) {
            $this->qrLoginService->expire($session);

            $this->telegram->sendMessage(
                $chatId,
                'QR kod muddati tugagan. '
                . 'Iltimos, yangi QR kod yarating.'
            );

            return;
        }

        /*
         * Find already activated staff
         */
        $staff = Staff::query()
            ->where(
                'telegram_chat_id',
                (string) $chatId
            )
            ->first();

        if (! $staff) {
            $this->telegram->sendMessage(
                $chatId,
                'Siz tizimda ro‘yxatdan o‘tmagansiz. '
                . 'Avval admin bergan aktivatsiya tokeni '
                . 'orqali botga kiring.'
            );

            return;
        }

        /*
         * Staff must be active
         */
        if (! $staff->isActive()) {
            $this->telegram->sendMessage(
                $chatId,
                'Sizning akkauntingiz faol emas.'
            );

            return;
        }

        /*
         * Attach staff to QR session
         */
        $session->update([
            'staff_id' => $staff->id,
        ]);

        /*
         * Ask for confirmation
         */
        $this->telegram->sendMessage(
            $chatId,
            "🔐 Dashboard'ga kirish so‘rovi.\n\n"
            . "Foydalanuvchi: {$staff->full_name}\n\n"
            . "Ushbu brauzerda tizimga kirishni "
            . "tasdiqlaysizmi?",
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Tasdiqlash',
                            'callback_data' =>
                                "qr_approve:{$session->id}",
                        ],
                        [
                            'text' => '❌ Bekor qilish',
                            'callback_data' =>
                                "qr_cancel:{$session->id}",
                        ],
                    ],
                ],
            ],
        );
    }

    private function sendActivationSuccess(
        int|string $chatId,
        $activation
    ): void {
        $staff = $activation->staff;

        $login = $activation->login;
        $password = $activation->password;

        $message =
            "✅ <b>Akkauntingiz muvaffaqiyatli faollashtirildi!</b>\n\n"
            . "Xush kelibsiz, <b>{$staff->full_name}</b>!\n\n"
            . "🔐 <b>Dashboard uchun ma'lumotlaringiz:</b>\n\n"
            . "👤 Login: <code>{$login}</code>\n"
            . "🔑 Parol: <code>{$password}</code>\n\n"
            . "🌐 Ushbu login va parol orqali dashboardga kirishingiz mumkin.\n\n"
            . "⚠️ Ushbu ma'lumotlarni boshqa shaxslarga bermang.";

        $this->telegram->sendMessage(
            $chatId,
            $message,
            $this->mainKeyboard(),
            'HTML',
        );
    }
    private function mainKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => '📋 Vazifalarni boshqarish'],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
            'one_time_keyboard' => false,
        ];
    }
}
