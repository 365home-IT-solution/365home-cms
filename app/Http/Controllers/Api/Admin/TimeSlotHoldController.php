<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\TimeSlotHoldController as GuestTimeSlotHoldController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SlotRealtimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Product\App\Models\Product;

/**
 * Giữ chỗ tạm (time-slot hold) RIÊNG cho admin — dùng CHUNG kho dữ liệu (cache key
 * "time_slot_holds:{roomId}") và CHUNG kênh realtime với khách hàng/khách vãng lai
 * (GuestTimeSlotHoldController), để 2 phía thấy đúng ngay khi có người giữ/bỏ giữ 1 ô, dù đang
 * dùng app nào.
 *
 * KHÁC với phía khách hàng ở chỗ KHÔNG nhận session_id từ client — định danh người giữ suy thẳng
 * từ token Sanctum đã xác thực ("admin:{user_id}"), nên không ai giả mạo/huỷ hộ được hold của
 * admin khác (khác hẳn lỗ hổng session_id tự khai phía khách vãng lai). Không cần thêm throttle
 * thủ công — auth:sanctum + admin.api đã tự giới hạn chỉ tài khoản admin thật gọi được.
 */
class TimeSlotHoldController extends Controller
{
    private const TTL = 600; // 10 phút — khớp TTL bên khách hàng (cùng 1 kho dữ liệu)

    // POST /api/admin/rooms/{id}/time-slot-hold
    public function hold(Request $request, string $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $data = $request->validate([
            'timeslot_id' => 'required|integer',
            'date'        => 'required|string', // d-m-Y, khớp $date['date'] của GET .../time-slots
        ]);

        $room = Product::where('id', $id)->where('is_activated', true)->first();

        if (! $room || (! $admin->isSuperAdmin() && $room->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $holderId  = 'admin:' . $admin->id;
        $expiresAt = now()->addSeconds(self::TTL)->toIso8601String();
        $holds     = GuestTimeSlotHoldController::getActiveHolds($id);

        // Bỏ hold cũ CÙNG admin cho đúng ô này (nếu có) trước khi thêm lại — tránh trùng lặp khi
        // admin gọi hold() nhiều lần liên tiếp cho cùng 1 ô.
        $holds = array_values(array_filter($holds, fn ($h) => ! ($h['session_id'] === $holderId
            && (int) $h['timeslot_id'] === (int) $data['timeslot_id']
            && $h['date'] === $data['date'])));

        $holds[] = [
            'session_id'  => $holderId,
            'timeslot_id' => (int) $data['timeslot_id'],
            'date'        => $data['date'],
            'expires_at'  => $expiresAt,
        ];

        Cache::put("time_slot_holds:{$id}", $holds, self::TTL);
        $this->broadcastForDate($id, $data['date'], $holds);

        return response()->json(['ok' => true, 'expires_at' => $expiresAt]);
    }

    // DELETE /api/admin/rooms/{id}/time-slot-hold
    public function release(Request $request, string $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $data = $request->validate([
            'timeslot_id' => 'required|integer',
            'date'        => 'required|string',
        ]);

        $holderId = 'admin:' . $admin->id;
        $holds    = GuestTimeSlotHoldController::getActiveHolds($id);
        $holds    = array_values(array_filter($holds, fn ($h) => ! ($h['session_id'] === $holderId
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

    /**
     * Không phát holder ra ngoài — cùng lý do đã áp dụng cho phía khách hàng (xem
     * GuestTimeSlotHoldController). Đổi $date (d-m-Y) sang Y-m-d TRƯỚC khi bắn — kênh Node
     * "room:{room_id}:{date}" được client subscribe bằng data-iso-date (Y-m-d, xem
     * resources/js/ws-client.js), gửi thẳng d-m-Y sẽ join sai kênh, không ai nghe được (cùng lỗi
     * đã sửa ở GuestTimeSlotHoldController).
     */
    private function broadcastForDate(string $roomId, string $date, array $allHolds): void
    {
        $holdsForDate = array_values(array_filter($allHolds, fn ($h) => $h['date'] === $date));
        $isoDate      = \Carbon\Carbon::createFromFormat('d-m-Y', $date)->toDateString();

        app(SlotRealtimeService::class)->broadcastSlotHold($roomId, $isoDate, array_map(
            fn ($h) => ['timeslot_id' => $h['timeslot_id']],
            $holdsForDate
        ));
    }
}
