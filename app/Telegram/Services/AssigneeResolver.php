<?php

namespace App\Telegram\Services;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Collection;

class AssigneeResolver
{
    /**
     * Find all active staff members that could plausibly match the
     * supplied assignee name.
     *
     * IMPORTANT:
     * - Never collapse staff records.
     * - Never choose a "best" staff member.
     * - Every matching Staff record is returned.
     * - Exact SQL matching is preserved as the first step.
     *
     * @return Collection<int, Staff>
     */
    public function findCandidates(
        string $name,
        int $chatId,
    ): Collection {
        $name = trim($name);

        if ($name === '') {
            return new Collection();
        }

        /*
         * 1. Preserve the original behavior.
         *
         * If the database can find the name directly, return those
         * records exactly as before.
         */
        $directMatches = Staff::query()
            ->where('status', 'active')
            ->where('group_chat_id', $chatId)
            ->where(function ($query) use ($name) {
                $query
                    ->where('full_name', 'like', "%{$name}%")
                    ->orWhere('username', 'like', "%{$name}%");
            })
            ->get();

        if ($directMatches->isNotEmpty()) {
            return $directMatches;
        }

        /*
         * 2. No direct match.
         *
         * Load all active staff from this Telegram group and compare
         * each Staff independently.
         */
        $staff = Staff::query()
            ->where('status', 'active')
            ->where('group_chat_id', $chatId)
            ->get();

        $matches = new Collection();

        foreach ($staff as $candidate) {
            if ($this->matches($name, $candidate)) {
                $matches->push($candidate);
            }
        }

        return $matches->values();
    }

    private function matches(string $input, Staff $staff): bool
    {
        $inputVariants = $this->variants($input);

        /*
         * Compare against full name.
         */
        foreach ($this->tokens($staff->full_name) as $token) {
            foreach ($inputVariants as $inputVariant) {
                if ($this->isStrongMatch($inputVariant, $token)) {
                    return true;
                }
            }
        }

        /*
         * Also compare against username.
         *
         * Username can be null.
         */
        if (!empty($staff->username)) {
            foreach ($this->tokens($staff->username) as $token) {
                foreach ($inputVariants as $inputVariant) {
                    if ($this->isStrongMatch($inputVariant, $token)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Generate non-destructive comparison variants.
     *
     * The original value is NEVER changed in the Staff model.
     *
     * @return array<int, string>
     */
    private function variants(string $value): array
    {
        $value = $this->normalize($value);

        if ($value === '') {
            return [];
        }

        $variants = [
            $value,

            // Shukrulloh -> shukrullo
            // Abdulloh   -> abdullo
            $this->withoutFinalH($value),

            // Muzaffarga -> muzaffar
            // Muzaffarni -> muzaffar
            $this->withoutCaseEnding($value),

            // Muzaffar -> muzafar
            // Shukrullo -> shukro
            $this->collapseRepeatedCharacters($value),

            // Shaxzoda <-> Shahzoda
            $this->replaceXWithH($value),
            $this->replaceHWithX($value),
        ];

        /*
         * Apply transformations to transformed values as well.
         */
        $expanded = [];

        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }

            $expanded[] = $variant;
            $expanded[] = $this->withoutFinalH($variant);
            $expanded[] = $this->withoutCaseEnding($variant);
            $expanded[] = $this->collapseRepeatedCharacters($variant);
            $expanded[] = $this->replaceXWithH($variant);
            $expanded[] = $this->replaceHWithX($variant);
        }

        return array_values(
            array_unique(
                array_filter($expanded)
            )
        );
    }

    /**
     * Decide whether two individual name tokens are sufficiently similar.
     */
    private function isStrongMatch(string $input, string $candidate): bool
    {
        $input = $this->normalize($input);
        $candidate = $this->normalize($candidate);

        if ($input === '' || $candidate === '') {
            return false;
        }

        /*
         * Exact normalized match.
         */
        if ($input === $candidate) {
            return true;
        }

        /*
         * One is simply the other with repeated characters.
         *
         * Muzaffar <-> Muzafar
         * Shukrullo <-> Shukro
         */
        if (
            $this->collapseRepeatedCharacters($input)
            === $this->collapseRepeatedCharacters($candidate)
        ) {
            return true;
        }

        /*
         * h/x transliteration difference.
         *
         * Shaxzoda <-> Shahzoda
         */
        if (
            $this->replaceXWithH($input)
            === $this->replaceXWithH($candidate)
        ) {
            return true;
        }

        /*
         * One name can be a shortened pronunciation/transcription
         * of another.
         *
         * Zafar -> Muzaffar
         *
         * zafar is a subsequence of muzaffar:
         *
         * m u Z A F F A R
         *     Z A   A R
         *
         * This is useful for speech-to-text mistakes where Gemini
         * drops syllables or prefixes.
         */
        if ($this->isPlausibleSubsequence($input, $candidate)) {
            return true;
        }

        /*
         * Finally use conservative edit-distance matching.
         */
        return $this->isCloseEnough($input, $candidate);
    }

    /**
     * Determine whether the shorter string can be obtained from the
     * longer string by dropping a small number of characters.
     *
     * Examples:
     *
     * jasr   -> jasur
     * zafar  -> muzaffar
     * muzafar -> muzaffar
     */
    private function isPlausibleSubsequence(
        string $first,
        string $second,
    ): bool {
        $firstLength = mb_strlen($first);
        $secondLength = mb_strlen($second);

        if ($firstLength < 4 || $secondLength < 4) {
            return false;
        }

        /*
         * Work with the shorter value as the requested/transcribed name.
         */
        if ($firstLength <= $secondLength) {
            $shorter = $first;
            $longer = $second;
        } else {
            $shorter = $second;
            $longer = $first;
        }

        /*
         * Don't allow arbitrarily long omissions.
         *
         * zafar -> muzaffar = 3 extra characters
         * jasr  -> jasur    = 1 extra character
         *
         * But "ali" -> "alisherbek..." should not match.
         */
        $lengthDifference = mb_strlen($longer) - mb_strlen($shorter);

        if ($lengthDifference > 3) {
            return false;
        }

        $shorterChars = preg_split('//u', $shorter, -1, PREG_SPLIT_NO_EMPTY);
        $longerChars = preg_split('//u', $longer, -1, PREG_SPLIT_NO_EMPTY);

        $shortIndex = 0;

        foreach ($longerChars as $char) {
            if (
                isset($shorterChars[$shortIndex])
                && $char === $shorterChars[$shortIndex]
            ) {
                $shortIndex++;
            }

            if ($shortIndex === count($shorterChars)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Conservative fuzzy comparison.
     *
     * Uses both edit distance and similar_text().
     */
    private function isCloseEnough(
        string $input,
        string $candidate,
    ): bool {
        $inputLength = mb_strlen($input);
        $candidateLength = mb_strlen($candidate);

        /*
         * Very short names are dangerous to fuzzy-match.
         */
        if ($inputLength <= 3 || $candidateLength <= 3) {
            return false;
        }

        $maxLength = max($inputLength, $candidateLength);

        $distance = levenshtein($input, $candidate);

        $editSimilarity = 1 - ($distance / $maxLength);

        similar_text(
            $input,
            $candidate,
            $similarTextPercent
        );

        $similarity = $similarTextPercent / 100;

        /*
         * Either metric being strong enough is sufficient.
         *
         * This catches:
         *
         * Gulsara  -> Gulsera
         * Shukrulo -> Shukrullo
         * Jasur    -> Jasr
         */
        return $editSimilarity >= 0.78
            || $similarity >= 0.82;
    }

    /**
     * Split full names/usernames into individual tokens.
     *
     * "Muzaffar Karimov" becomes:
     * ["muzaffar", "karimov"]
     *
     * This lets "Muzaffar" match the first name.
     *
     * @return array<int, string>
     */
    private function tokens(?string $value): array
    {
        if (!$value) {
            return [];
        }

        $value = $this->normalize($value);

        return array_values(
            array_filter(
                preg_split('/\s+/u', $value) ?: []
            )
        );
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        /*
         * Remove punctuation but preserve Unicode letters/numbers.
         */
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? '';

        return trim($value);
    }

    private function withoutFinalH(string $value): string
    {
        if (mb_strlen($value) <= 4) {
            return $value;
        }

        return preg_replace('/h$/u', '', $value) ?? $value;
    }

    private function withoutCaseEnding(string $value): string
    {
        /*
         * Uzbek grammatical endings commonly introduced by voice/text
         * input.
         *
         * Only remove them from comparison variants.
         */
        $endings = [
            'lariga',
            'larga',
            'ning',
            'dan',
            'ga',
            'ka',
            'qa',
            'ni',
            'da',
        ];

        foreach ($endings as $ending) {
            if (
                mb_strlen($value) > mb_strlen($ending) + 3
                && str_ends_with($value, $ending)
            ) {
                return mb_substr(
                    $value,
                    0,
                    mb_strlen($value) - mb_strlen($ending)
                );
            }
        }

        return $value;
    }

    private function collapseRepeatedCharacters(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return preg_replace('/(.)\1+/u', '$1', $value) ?? $value;
    }

    private function replaceXWithH(string $value): string
    {
        return str_replace('x', 'h', $value);
    }

    private function replaceHWithX(string $value): string
    {
        return str_replace('h', 'x', $value);
    }
}
