<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Jobs\Telegram\ProcessTelegramMediaGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $update = $request->all();
        $updateId = $update['update_id'] ?? null;

        if (is_int($updateId) || (is_string($updateId) && ctype_digit($updateId))) {
            $processedKey = 'telegram:update:' . (string) $updateId;

            if (! Cache::add($processedKey, true, now()->addDay())) {
                return response()->json(['ok' => true, 'duplicate' => true]);
            }
        }

        $message = $update['message'] ?? null;
        $mediaGroupId = is_array($message)
            ? ($message['media_group_id'] ?? null)
            : null;

        if (is_string($mediaGroupId) && $mediaGroupId !== '' && is_array($message)) {
            $chatId = (string) ($message['chat']['id'] ?? '0');
            $cacheKey = "telegram:media-group:{$chatId}:{$mediaGroupId}";
            $scheduleKey = "{$cacheKey}:scheduled";

            $updates = Cache::get($cacheKey, []);
            $updates[] = $update;
            Cache::put($cacheKey, $updates, now()->addSeconds(15));

            if (Cache::add($scheduleKey, true, now()->addSeconds(15))) {
                ProcessTelegramMediaGroup::dispatch($cacheKey)
                    ->delay(now()->addSeconds(2));
            }

            return response()->json(['ok' => true]);
        }

        ProcessTelegramUpdate::dispatch($update);

        return response()->json([
            'ok' => true,
        ]);
    }
}
