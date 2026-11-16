<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GameChatService;
use App\Services\GameSoundPackService;
use App\Services\CaroService;
use App\Services\GameVoiceService;
use App\Services\GameStickerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Âm thanh, sticker và chat trong trận cho mini game Tiến lên + Caro (khách đã đăng nhập).
 * Route khai báo trong nhóm của từng game (routes/api.php), game truyền qua ->defaults('game', …).
 *
 *  - GET  /api/games/{tien-len|caro}/sound-packs            danh sách bộ âm thanh + bộ đang dùng + tuỳ chọn âm lượng + danh sách sự kiện
 *  - PUT  /api/games/{tien-len|caro}/sound-packs/selected   chọn bộ (pack)
 *  - PUT  /api/games/{tien-len|caro}/sound-settings         bật/tắt hiệu ứng, nhạc nền, âm lượng
 *  - GET  /api/games/{tien-len|caro}/stickers               các bộ sticker dùng được trong game
 *  - GET|POST /api/games/tien-len/rooms/{id}/chat           lịch sử tạm / gửi tin trong phòng Tiến lên
 *  - GET|POST /api/games/caro/matches/{id}/chat             lịch sử tạm / gửi tin trong trận Caro
 *  - POST /api/games/tien-len/rooms/{id}/voice             vé vào phòng thoại + ICE server (WebRTC + TURN), xem GameVoiceService
 *  - POST /api/games/caro/matches/{id}/voice               như trên cho trận Caro đang đánh
 */
class GameSocialController extends Controller
{
    public function __construct(private readonly GameChatService $chat, private readonly GameVoiceService $voice, private readonly CaroService $caro)
    {
    }

    public function soundPacks(Request $request, string $game): JsonResponse
    {
        return response()->json(['data' => GameSoundPackService::catalog($game, (string) $request->user()->id)]);
    }

    public function selectSoundPack(Request $request, string $game): JsonResponse
    {
        $data = $request->validate(['pack' => 'required|string|max:50']);
        GameSoundPackService::select($game, (string) $request->user()->id, $data['pack']);

        return $this->soundPacks($request, $game);
    }

    public function updateSoundSettings(Request $request, string $game): JsonResponse
    {
        $data = $request->validate([
            'sfx_enabled'   => 'sometimes|boolean',
            'music_enabled' => 'sometimes|boolean',
            'sfx_volume'    => 'sometimes|integer|min:0|max:100',
            'music_volume'  => 'sometimes|integer|min:0|max:100',
        ]);
        GameSoundPackService::updateSettings($game, (string) $request->user()->id, $data);

        return response()->json(['data' => GameSoundPackService::settingsFor($game, (string) $request->user()->id)]);
    }

    public function stickers(Request $request, string $game): JsonResponse
    {
        return response()->json(['data' => GameStickerService::catalog($game)]);
    }

    public function tienLenChat(Request $request, string $id): JsonResponse
    {
        return $this->guard(fn () => response()->json(['data' => $this->chat->tienLenHistory($request->user(), $id)]), 'Bạn không ở trong phòng này.');
    }

    public function sendTienLenChat(Request $request, string $id): JsonResponse
    {
        $data = $this->validateMessage($request);

        return $this->guard(fn () => response()->json(['data' => $this->chat->sendToTienLenRoom($request->user(), $id, $data)], 201), 'Bạn không ở trong phòng này.');
    }

    public function caroChat(Request $request, string $id): JsonResponse
    {
        return $this->guard(fn () => response()->json(['data' => $this->chat->caroHistory($request->user(), $id)]), 'Không tìm thấy trận đấu.');
    }

    public function sendCaroChat(Request $request, string $id): JsonResponse
    {
        $data = $this->validateMessage($request);

        return $this->guard(fn () => response()->json(['data' => $this->chat->sendToCaroMatch($request->user(), $id, $data)], 201), 'Không tìm thấy trận đấu.');
    }

    public function tienLenVoice(Request $request, string $id): JsonResponse
    {
        return $this->guard(fn () => response()->json(['data' => $this->voice->tienLenJoin($request->user(), $id)]), 'Bạn không ở trong phòng này.');
    }

    public function caroVoice(Request $request, string $id): JsonResponse
    {
        return $this->guard(fn () => response()->json(['data' => $this->voice->caroJoin($request->user(), $id, $this->caro->publicName($request->user()))]), 'Không tìm thấy trận đấu.');
    }

    private function validateMessage(Request $request): array
    {
        return $request->validate([
            'type'       => 'required|in:' . GameChatService::TYPE_TEXT . ',' . GameChatService::TYPE_STICKER,
            'text'       => 'required_if:type,' . GameChatService::TYPE_TEXT . '|nullable|string|max:2000',
            'sticker_id' => 'required_if:type,' . GameChatService::TYPE_STICKER . '|nullable|integer',
        ], [
            'text.required_if'       => 'Nhập nội dung tin nhắn.',
            'sticker_id.required_if' => 'Chọn sticker.',
        ]);
    }

    private function guard(\Closure $fn, string $notFound): JsonResponse
    {
        try {
            return $fn();
        } catch (ModelNotFoundException) {
            return response()->json(['message' => $notFound], 404);
        }
    }
}
