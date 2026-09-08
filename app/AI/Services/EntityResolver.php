<?php

namespace App\AI\Services;

use App\Models\Staff;
use Illuminate\Support\Facades\Log;

class EntityResolver
{
    /**
     * Resolve an active staff member by name, username, or login.
     *
     * Group resolution is intentionally not supported.
     *
     * Telegram chat/group context is handled outside the AI entity
     * resolver and must never be represented as a Group model here.
     */
    public function resolveStaff(
        ?string $name,
    ): ?Staff {

        $originalName = $name;

        $name = $this->normalizeEntityName($name);

        Log::debug('Staff resolution', [
            'original' => $originalName,
            'normalized' => $name,
        ]);

        if ($name === null) {
            return null;
        }

        $query = Staff::query()
            ->where('status', 'active');

        /*
         * 1. Exact match.
         *
         * Exact matches are preferred because they are deterministic.
         */
        $exact = (clone $query)
            ->where(function ($query) use ($name) {
                $query
                    ->whereRaw(
                        'LOWER(TRIM(full_name)) = ?',
                        [$name]
                    )
                    ->orWhereRaw(
                        'LOWER(TRIM(name)) = ?',
                        [$name]
                    )
                    ->orWhereRaw(
                        'LOWER(TRIM(username)) = ?',
                        [$name]
                    )
                    ->orWhereRaw(
                        'LOWER(TRIM(login)) = ?',
                        [$name]
                    );
            })
            ->first();

        if ($exact) {
            return $exact;
        }

        /*
         * 2. Partial match.
         *
         * A partial match is only accepted when exactly one staff
         * member matches. Multiple matches require clarification.
         */
        $matches = (clone $query)
            ->where(function ($query) use ($name) {
                $search = '%' . $name . '%';

                $query
                    ->whereRaw(
                        'LOWER(full_name) LIKE ?',
                        [$search]
                    )
                    ->orWhereRaw(
                        'LOWER(name) LIKE ?',
                        [$search]
                    )
                    ->orWhereRaw(
                        'LOWER(username) LIKE ?',
                        [$search]
                    )
                    ->orWhereRaw(
                        'LOWER(login) LIKE ?',
                        [$search]
                    );
            })
            ->get();

        /*
         * Exactly one match = safe.
         */
        if ($matches->count() === 1) {
            return $matches->first();
        }

        /*
         * Zero or multiple matches = clarification required.
         */
        return null;
    }

    /**
     * Normalize a human-entered entity name.
     */
    private function normalizeEntityName(
        ?string $value
    ): ?string {
        if (! $value) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        /*
         * Normalize whitespace.
         */
        $value = preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

        /*
         * Remove surrounding punctuation.
         */
        $value = trim(
            $value,
            " \t\n\r\0\x0B.,!?;:\"'()[]{}"
        );

        /*
         * Remove common Uzbek grammatical endings.
         *
         * This is intentionally conservative.
         */
        $suffixes = [
            'laringizga',
            'laringizni',
            'laringizdan',
            'laringizning',
            'laringizda',

            'lariga',
            'larini',
            'laridan',
            'larining',
            'larida',

            'ingizga',
            'ingizni',
            'ingizdan',
            'ingizning',
            'ingizda',
            'ingiz',

            'ga',
            'ka',
            'qa',
            'ni',
            'ning',
            'dan',
            'da',
        ];

        $lower = mb_strtolower($value);

        foreach ($suffixes as $suffix) {
            if (
                mb_strlen($lower) >
                mb_strlen($suffix) + 2
                && str_ends_with($lower, $suffix)
            ) {
                $value = mb_substr(
                    $value,
                    0,
                    mb_strlen($value) - mb_strlen($suffix)
                );

                break;
            }
        }

        return mb_strtolower(trim($value));
    }
}
