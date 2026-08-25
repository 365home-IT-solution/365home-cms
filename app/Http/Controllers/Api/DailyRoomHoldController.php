<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\SlotRealtimeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Modules\Product\App\Models\Product;

class DailyRoomHoldController extends Controller
{
    private const TTL     = 600; // 10 phút
    private const MAX_TTL = 600;

    // POST /api/rooms/{id}/hold
    public function hold(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'checkin'    => 'required|date_format:Y-m-d',
            'checkout'   => 'required|date_format:Y-m-d|after:checkin',
            'session_id' => 'required|string|max:64',
        ]);

        $room = Product::where('id', $id)->where('is_activated', true)->where('styles', 2)->first();
        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $sessionId = $request->input('session_id');
        $expiresAt = now()->addSeconds(self::TTL)->toIso8601String();

        $holds = $this->getActiveHolds($id, $sessionId);
        $holds[] = [
            'session_id' => $sessionId,
            'checkin'    => $request->input('checkin'),
            'checkout'   => $request->input('checkout'),
            'expires_at' => $expiresAt,
        ];

        Cache::put("daily_holds:{$id}", $holds, self::MAX_TTL);
        app(SlotRealtimeService::class)->broadcastDailyHold($id, $this->stripHolderId($holds));

        return response()->json(['ok' => true, 'expires_at' => $expiresAt]);
    }

    // DELETE /api/rooms/{id}/hold
    public function release(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string|max:64',
        ]);

        $sessionId = $request->input('session_id');
        $holds     = $this->getActiveHolds($id, $sessionId);

        if (empty($holds)) {
            Cache::forget("daily_holds:{$id}");
        } else {
            Cache::put("daily_holds:{$id}", $holds, self::MAX_TTL);
        }

        app(SlotRealtimeService::class)->broadcastDailyHold($id, $this->stripHolderId($holds));

        return response()->json(['ok' => true]);
    }

    /** Lấy holds còn hạn, loại bỏ session hiện tại (để replace). */
    private function getActiveHolds(string $roomId, string $excludeSession): array
    {
        $raw = Cache::get("daily_holds:{$roomId}", []);

        return array_values(array_filter($raw, function ($h) use ($excludeSession) {
            if (($h['session_id'] ?? '') === $excludeSession) return false;
            return Carbon::parse($h['expires_at'] ?? now()->subSecond())->isFuture();
        }));
    }

    /**
     * Không phát session_id ra ngoài qua broadcast — cùng lý do/cùng fix đã áp dụng cho
     * TimeSlotHoldController (phòng khung giờ): để lộ session_id cho mọi client khác đang xem cùng
     * phòng sẽ cho phép giả mạo release() hộ người khác mà không cần xác thực gì.
     */
    private function stripHolderId(array $holds): array
    {
        return array_map(fn ($h) => ['checkin' => $h['checkin'], 'checkout' => $h['checkout']], $holds);
    }
}
