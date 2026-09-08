<?php

namespace App\Services\Authentication;

use App\Models\QrLoginSession;
use Illuminate\Support\Str;

class QrLoginService
{
    private const TOKEN_LENGTH = 48;

    private const SESSION_LIFETIME_MINUTES = 3;

    /**
     * Create a new QR login session.
     *
     * @return array{
     *     session: QrLoginSession,
     *     token: string
     * }
     */
    public function create(string $browserHash): array
    {
        $token = Str::random(
            self::TOKEN_LENGTH
        );

        $session = QrLoginSession::create([
            'token_hash' => hash(
                'sha256',
                $token
            ),

            'browser_hash' => $browserHash,

            'status' => QrLoginSession::STATUS_PENDING,

            'expires_at' => now()->addMinutes(
                self::SESSION_LIFETIME_MINUTES
            ),
        ]);

        return [
            'session' => $session,
            'token' => $token,
        ];
    }

    /**
     * Find QR login session by token.
     */
    public function findByToken(
        string $token
    ): ?QrLoginSession {
        return QrLoginSession::query()
            ->where(
                'token_hash',
                hash('sha256', $token)
            )
            ->first();
    }

    /**
     * Expire a pending QR login session.
     */
    public function expire(
        QrLoginSession $session
    ): void {
        if ($session->isPending()) {
            $session->update([
                'status' => QrLoginSession::STATUS_EXPIRED,
            ]);
        }
    }

    /**
     * Approve a pending QR login session.
     */
    public function approve(
        QrLoginSession $session
    ): bool {
        if (! $session->isPending()) {
            return false;
        }

        if ($session->isExpired()) {
            $this->expire($session);

            return false;
        }

        $session->update([
            'status' => QrLoginSession::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        return true;
    }

    /**
     * Cancel a pending QR login session.
     */
    public function cancel(
        QrLoginSession $session
    ): bool {
        if (! $session->isPending()) {
            return false;
        }

        $session->update([
            'status' => QrLoginSession::STATUS_CANCELLED,
        ]);

        return true;
    }

    /**
     * Consume an approved QR login session.
     *
     * Prevents the same QR login session from being reused.
     */
    public function consume(
        QrLoginSession $session
    ): bool {
        if (! $session->isApproved()) {
            return false;
        }

        return $session->update([
            'status' => QrLoginSession::STATUS_CONSUMED,
        ]);
    }
}
