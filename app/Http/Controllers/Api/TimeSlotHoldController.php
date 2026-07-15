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

/**
 * "Đang chọn" tạm thời cho phòng theo khung giờ (style 1) — khi khách bấm chọn 1 ô khung giờ x
 * ngày trên lịch (GET /api/v1/branches/{slug}/time-slots) nhưng CHƯA bấm đặt phòng, hold này báo
 * cho những khách khác đang xem cùng phòng biết ô đó đang có người chọn (is_selectable=false, xem
 * BranchController::buildSlotStatus()), tránh 2 người cùng chọn trùng 1 ô rồi 1 người bị từ chối
 * lúc thanh toán. Cùng pattern TTL/Cache với DailyRoomHoldController (phòng theo ngày) nhưng
 * TOGGLE TỪNG Ô riêng lẻ thay vì thay thế cả danh sách — phòng theo khung giờ cho chọn nhiều ô
 * rời rạc (không liền kề) nên không thể gộp thành 1 khoảng checkin/checkout như phòng theo ngày.
 */
class TimeSlotHoldController extends Controller
{
    private const TTL = 600; // 10 phút — hết hạn tự động nếu khách rời trang không release

    // POST /api/rooms/{id}/time-slot-hold
    public function hold(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'session_id'  => 'required|string|max:64',
            'timeslot_id' => 'required|integer',
            'date'        => 'required|string', // format d-m-Y, khớp $date['date'] của /time-slots
        ]);

        $room = Product::where('id', $id)->where('is_activated', true)->first();
        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $expiresAt = now()->addSeconds(self::TTL)->toIso8601String();
        $holds     = $this->getActiveHolds($id);

        // Bỏ hold cũ CÙNG session cho đúng ô này (nếu có) trước khi thêm lại — tránh trùng lặp khi
        // khách gọi hold() nhiều lần liên tiếp cho cùng 1 ô (VD: giữ TTL bằng cách gọi lại định kỳ).
        $holds = array_values(array_filter($holds, fn ($h) => ! ($h['session_id'] === $data['session_id']
            && (int) $h['timeslot_id'] === (int) $data['timeslot_id']
            && $h['date'] === $data['date'])));

        $holds[] = [
            'session_id'  => $data['session_id'],
            'timeslot_id' => (int) $data['timeslot_id'],
            'date'        => $data['date'],
            'expires_at'  => $expiresAt,
        ];

        Cache::put("time_slot_holds:{$id}", $holds, self::TTL);
        $this->broadcastForDate($id, $data['date'], $holds);

        return response()->json(['ok' => true, 'expires_at' => $expiresAt]);
    }

    // DELETE /api/rooms/{id}/time-slot-hold
    public function release(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'session_id'  => 'required|string|max:64',
            'timeslot_id' => 'required|integer',
            'date'        => 'required|string',
        ]);

        $holds = $this->getActiveHolds($id);
        $holds = array_values(array_filter($holds, fn ($h) => ! ($h['session_id'] === $data['session_id']
            && (int) $h['timeslot_id'] === (int) $data['timeslot_id']
            && $h['date'] === $data['date'])));

        if (empty($holds)) {
            Cache::forget("time_slot_holds:{$id}");
        } else {
            Cache::put("time_slot_holds:{$id}", $holds, self::TTL);
        }

        $this->broadcastForDate($id, $data['date'], $holds);

        return response()->json(['ok' => true]);
    }

    /** Holds còn hạn của 1 phòng — dùng chung bởi hold()/release() và BranchController (đọc). */
    public static function getActiveHolds(string $roomId): array
    {
        $raw = Cache::get("time_slot_holds:{$roomId}", []);

        return array_values(array_filter($raw, fn ($h) => Carbon::parse($h['expires_at'] ?? now()->subSecond())->isFuture()));
    }

    /**
     * Chỉ bắn WS cho ĐÚNG ngày vừa thay đổi (không phải toàn bộ holds của phòng) — kể cả khi rỗng
     * (vừa release hold cuối của ngày đó), để client subscribe kênh room:{room_id}:{date} (cùng
     * kênh slot.updated) biết chính xác trạng thái ngày đang xem, không suy đoán qua im lặng.
     */
    private function broadcastForDate(string $roomId, string $date, array $allHolds): void
    {
        $holdsForDate = array_values(array_filter(
            $allHolds,
            fn ($h) => $h['date'] === $date
        ));

        app(SlotRealtimeService::class)->broadcastSlotHold($roomId, $date, array_map(
            fn ($h) => ['session_id' => $h['session_id'], 'timeslot_id' => $h['timeslot_id']],
            $holdsForDate
        ));
    }
}
