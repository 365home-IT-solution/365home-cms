<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SlotRealtimeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Product\App\Models\Product;

/**
 * Giữ chỗ tạm cho phòng theo NGÀY (styles=2) — bản admin của DailyRoomHoldController (khách hàng).
 * Dùng CHUNG kho dữ liệu (cache key "daily_holds:{roomId}") + kênh realtime để 2 phía thấy nhau
 * ngay, nhưng định danh bằng chính token admin ("admin:{user_id}") thay vì session_id tự khai —
 * cùng nguyên tắc bảo mật đã áp dụng cho Admin\TimeSlotHoldController (phòng theo khung giờ).
 * Không phát holder ra broadcast (cùng lý do đã vá ở DailyRoomHoldController::stripHolderId()).
 */
class DailyRoomHoldController extends Controller
{
    private const TTL = 600; // 10 phút — khớp TTL bên khách hàng (cùng 1 kho dữ liệu)

    // POST /api/admin/rooms/{id}/hold
    public function hold(Request $request, string $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $request->validate([
            'checkin'  => 'required|date_format:Y-m-d',
            'checkout' => 'required|date_format:Y-m-d|after:checkin',
        ]);

        $room = Product::where('id', $id)->where('is_activated', true)->where('styles', 2)->first();

        if (! $room || (! $admin->isSuperAdmin() && $room->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $holderId  = 'admin:' . $admin->id;
        $expiresAt = now()->addSeconds(self::TTL)->toIso8601String();

        $holds   = $this->getActiveHolds($id, $holderId);
        $holds[] = [
            'session_id' => $holderId,
            'checkin'    => $request->input('checkin'),
            'checkout'   => $request->input('checkout'),
            'expires_at' => $expiresAt,
        ];

        Cache::put("daily_holds:{$id}", $holds, self::TTL);
        app(SlotRealtimeService::class)->broadcastDailyHold($id, $this->stripHolderId($holds));

        return response()->json(['ok' => true, 'expires_at' => $expiresAt]);
    }

    // DELETE /api/admin/rooms/{id}/hold
    public function release(Request $request, string $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $holderId = 'admin:' . $admin->id;
        $holds    = $this->getActiveHolds($id, $holderId);

        if (empty($holds)) {
            Cache::forget("daily_holds:{$id}");
        } else {
            Cache::put("daily_holds:{$id}", $holds, self::TTL);
        }

        app(SlotRealtimeService::class)->broadcastDailyHold($id, $this->stripHolderId($holds));

        return response()->json(['ok' => true]);
    }

    /** Lấy holds còn hạn, loại bỏ chính admin đang gọi (để thay thế hold trước đó của họ). */
    private function getActiveHolds(string $roomId, string $excludeHolder): array
    {
        $raw = Cache::get("daily_holds:{$roomId}", []);

        return array_values(array_filter($raw, function ($h) use ($excludeHolder) {
            if (($h['session_id'] ?? '') === $excludeHolder) return false;
            return Carbon::parse($h['expires_at'] ?? now()->subSecond())->isFuture();
        }));
    }

    private function stripHolderId(array $holds): array
    {
        return array_map(fn ($h) => ['checkin' => $h['checkin'], 'checkout' => $h['checkout']], $holds);
    }
}
