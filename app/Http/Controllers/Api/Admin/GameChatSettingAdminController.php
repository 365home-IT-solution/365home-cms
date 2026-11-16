<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameChatSetting;
use App\Services\GameChatService;
use App\Support\MiniGameKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cấu hình chat / sticker trong trận (chỉ Super Admin) — cùng dữ liệu với Filament "Mini game — Chat".
 *
 *  - GET /api/admin/games/chat-settings            cấu hình của mọi game
 *  - PUT /api/admin/games/chat-settings/{game}     sửa: chat_enabled, sticker_enabled, voice_enabled, max_length, message_cooldown_seconds,
 *                                                  sticker_cooldown_seconds, history_size, banned_words[]
 */
class GameChatSettingAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => array_map(fn (string $game) => GameChatService::settingsPayload(GameChatSetting::for($game)), MiniGameKey::all())]);
    }

    public function update(Request $request, string $game): JsonResponse
    {
        $this->authorizeSuper($request);
        abort_unless(in_array($game, MiniGameKey::all(), true), 404, 'Game không tồn tại.');

        $rules = [
            'chat_enabled'    => 'sometimes|boolean',
            'sticker_enabled' => 'sometimes|boolean',
            'voice_enabled'   => 'sometimes|boolean',
            'banned_words'    => 'sometimes|array|max:2000',
            'banned_words.*'  => 'nullable|string|max:50',
        ];
        foreach (GameChatService::SETTING_LIMITS as $field => [$min, $max]) {
            $rules[$field] = "sometimes|integer|min:{$min}|max:{$max}";
        }

        $settings = GameChatService::updateSettings($game, $request->validate($rules));

        return response()->json(['data' => GameChatService::settingsPayload($settings)]);
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
