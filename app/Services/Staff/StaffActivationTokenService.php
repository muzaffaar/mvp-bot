<?php

namespace App\Services\Staff;

use App\Models\Staff;

final class StaffActivationTokenService
{
    private const LENGTH = 6;

    private const CHARACTERS =
        'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generate(): string
    {
        do {
            $token = $this->randomToken();
        } while (
            Staff::where('token', $this->hash($token))->exists()
        );

        return $token;
    }

    public function normalize(string $token): string
    {
        return strtoupper(trim($token));
    }

    public function hash(string $token): string
    {
        return hash(
            'sha256',
            $this->normalize($token)
        );
    }

    public function randomToken(): string
    {
        $characters = self::CHARACTERS;
        $token = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $token .= $characters[random_int(
                0,
                strlen($characters) - 1
            )];
        }

        return $token;
    }
}
