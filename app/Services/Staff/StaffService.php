<?php

namespace App\Services\Staff;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StaffService
{
    public function __construct(
        private readonly StaffActivationTokenService $tokenService,
    ) {
    }

    public function create(array $data): array
{
    return DB::transaction(function () use ($data) {

        $plainToken = $this->tokenService->generate();

        $plainPassword = $data['password'];

        $staff = Staff::create([
            'full_name' => $data['full_name'],
            'name' => $data['name'] ?? null,
            'username' => $data['username'] ?? null,
            'login' => $data['login'],

            // This gets hashed by your model cast/mutator.
            'password' => $plainPassword,

            // Temporarily keep plaintext password.
            'activation_password' => $plainPassword,

            'lavozim' => $data['lavozim'] ?? null,

            'telegram_chat_id' => null,

            'token' => $this->tokenService->hash(
                $plainToken
            ),

            'status' => 'inactive',
        ]);

        $staff->syncRoles([
            $data['role'],
        ]);

        $staff->groups()->sync(
            $data['group_ids'] ?? []
        );

        return [
            'staff' => $staff,
            'activation_token' => $plainToken,
        ];
    });
}

    public function regenerateToken(
        Staff $staff
    ): string {
        $plainToken = $this->tokenService->generate();

        $staff->update([
            'token' => $this->tokenService->hash(
                $plainToken
            ),
        ]);

        return $plainToken;
    }

    public function activateFromTelegram(
    string $token,
    string $telegramChatId,
    string $telegramUsername
): ?StaffActivationResult {

    $tokenHash = $this->tokenService->hash($token);

    return DB::transaction(function () use (
        $tokenHash,
        $telegramChatId,
        $telegramUsername
    ) {
        // Find staff using the hashed activation token
        $staff = Staff::query()
            ->where('token', $tokenHash)
            ->lockForUpdate()
            ->first();

        if (! $staff) {
            return null;
        }

        // Prevent one Telegram account from activating another staff account
        $alreadyUsed = Staff::query()
            ->where('telegram_chat_id', $telegramChatId)
            ->where('id', '!=', $staff->id)
            ->exists();

        if ($alreadyUsed) {
            return null;
        }

        // Get credentials before removing them
        $password = $staff->activation_password;

        if (! $password) {
            return null;
        }

        $login = $staff->login;

        // Activate staff
        $staff->update([
            'telegram_chat_id' => $telegramChatId,
            'status' => 'active',
            'token' => null,
            'activation_password' => null,
            'username' => $telegramUsername,
        ]);

        return new StaffActivationResult(
            staff: $staff->fresh(),
            login: $login,
            password: $password,
        );
    });
}
}
