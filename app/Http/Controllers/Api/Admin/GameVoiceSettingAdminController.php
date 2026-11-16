<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\VoiceConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cấu hình máy chủ TURN / STUN cho gọi thoại mini game (chỉ Super Admin) — cùng dữ liệu với Filament "Mini game — Chat → Máy chủ TURN".
 * Giá trị ở đây ưu tiên hơn .env; để trống ⇒ dùng .env. Khoá bí mật KHÔNG BAO GIỜ trả ra (chỉ có cờ turn_secret_set).
 *
 *  - GET /api/admin/games/voice-settings
 *  - PUT /api/admin/games/voice-settings   turn_urls[], stun_urls[], turn_secret, clear_turn_secret, turn_ttl (giây)
 */
class GameVoiceSettingAdminController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => VoiceConfig::adminPayload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);
        $data = $request->validate([
            'turn_urls'         => 'sometimes|nullable|array|max:10',
            'turn_urls.*'       => 'string|max:255',
            'stun_urls'         => 'sometimes|nullable|array|max:10',
            'stun_urls.*'       => 'string|max:255',
            'turn_secret'       => 'sometimes|nullable|string|max:255',
            'clear_turn_secret' => 'sometimes|boolean',
            'turn_ttl'          => 'sometimes|nullable|integer|min:300|max:604800',
        ]);

        VoiceConfig::save($data);

        return response()->json(['data' => VoiceConfig::adminPayload()]);
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
