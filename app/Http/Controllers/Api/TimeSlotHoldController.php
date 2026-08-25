<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\SlotRealtimeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * "Đang chọn" tạm thời cho phòng theo khung giờ (style 1) — khi khách bấm chọn 1 ô khung giờ x
 * ngày trên lịch (GET /api/v1/branches/{slug}/time-slots) nhưng CHƯA bấm đặt phòng, hold này báo
 * cho những khách khác đang xem cùng phòng biết ô đó đang có người chọn (is_selectable=false, xem
 * BranchController::buildSlotStatus()), tránh 2 người cùng chọn trùng 1 ô rồi 1 người bị từ chối
 * lúc thanh toán. Cùng pattern TTL/Cache với DailyRoomHoldController (phòng theo ngày) nhưng
 * TOGGLE TỪNG Ô riêng lẻ thay vì thay thế cả danh sách — phòng theo khung giờ cho chọn nhiều ô
 * rời rạc (không liền kề) nên không thể gộp thành 1 khoảng checkin/checkout như phòng theo ngày.
 *
 * hold()/release() TỪNG BỊ GỠ KHỎI ROUTE PUBLIC (routes/api.php) vì lỗ hổng DoS: throttle
 * request/phút/IP không chặn được kẻ cố ý đổi/rotate nhiều IP để spam giữ HẾT mọi ô còn trống của
 * 1 phòng, khiến khách thật không đặt được dù phòng còn trống. Mở lại route với 2 lớp chặn MỚI,
 * không phụ thuộc IP (attacker đổi IP/session vô hạn cũng không vượt qua được):
 *  1. MAX_HOLDS_PER_ROOM — trần tổng số hold đang hoạt động của 1 phòng (mọi session cộng lại) —
 *     dù attacker dùng bao nhiêu session/IP khác nhau, 1 phòng KHÔNG BAO GIỜ bị giữ hết sạch, luôn
 *     còn slot thật sự trống hiển thị cho khách khác.
 *  2. MAX_HOLDS_PER_SESSION — trần số hold đồng thời của ĐÚNG 1 session — chặn 1 session giả tự
 *     mình chiếm gần hết ngân sách của MAX_HOLDS_PER_ROOM, buộc attacker phải tạo NHIỀU session
 *     (chi phí cao hơn hẳn spam từ 1 session) mới có thể tiệm cận trần phòng, lúc đó lớp (1) đã
 *     chặn rồi. TTL 10 phút (không đổi) tự dọn rác nếu attacker vẫn cố vượt qua cả 2 lớp.
 */
class TimeSlotHoldController extends Controller
{
    private const TTL = 600; // 10 phút — hết hạn tự động nếu khách rời trang không release

    private const MAX_HOLDS_PER_ROOM    = 60; // đủ rộng cho vài khách đặt đơn lớn cùng lúc, vẫn luôn còn slot trống thật hiển thị dù bị spam
    private const MAX_HOLDS_PER_SESSION = 20; // đơn lớn (nhiều khung giờ x nhiều ngày, VD 10 ngày x 2 khung/ngày) vẫn giữ đủ — trần này chỉ để chặn 1 session TỰ CHIẾM gần hết ngân sách MAX_HOLDS_PER_ROOM, không nhằm giới hạn nhu cầu đặt thật

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

        // Chặn timeslot_id giả/không thuộc phòng này — trước đây không kiểm tra, 1 request thủ
        // công có thể giữ chỗ timeslot_id bất kỳ (không tồn tại hoặc thuộc phòng khác), tạo rác
        // dữ liệu hold vô nghĩa trong cache của phòng này.
        $slotExists = RoomTimeSlot::where('room_id', $room->id)
            ->where('timeslot_id', $data['timeslot_id'])
            ->whereNull('date')
            ->exists();
        if (! $slotExists) {
            return response()->json(['message' => 'Khung giờ không hợp lệ.'], 422);
        }

        $expiresAt = now()->addSeconds(self::TTL)->toIso8601String();
        $holds     = $this->getActiveHolds($id);

        // Bỏ hold cũ CÙNG session cho đúng ô này (nếu có) trước khi thêm lại — tránh trùng lặp khi
        // khách gọi hold() nhiều lần liên tiếp cho cùng 1 ô (VD: giữ TTL bằng cách gọi lại định kỳ).
        // Làm TRƯỚC 2 bước kiểm tra trần bên dưới — "làm mới" hold đã có của chính mình không được
        // tính là tạo mới, không được phép vô tình đẩy khách đó vượt trần chỉ vì gọi lại refresh.
        $holds = array_values(array_filter($holds, fn ($h) => ! ($h['session_id'] === $data['session_id']
            && (int) $h['timeslot_id'] === (int) $data['timeslot_id']
            && $h['date'] === $data['date'])));

        // Trần (2) — session này đã giữ đủ 8 ô khác rồi, không cho giữ thêm ô mới (vẫn cho refresh
        // ô cũ, xem điều kiện lọc ở trên).
        $sessionHoldCount = count(array_filter($holds, fn ($h) => $h['session_id'] === $data['session_id']));
        if ($sessionHoldCount >= self::MAX_HOLDS_PER_SESSION) {
            return response()->json(['message' => 'Bạn đang giữ tạm quá nhiều khung giờ cùng lúc, vui lòng hoàn tất hoặc huỷ bớt trước khi chọn thêm.'], 429);
        }

        // Trần (1) — phòng này đã đủ 40 hold đang hoạt động (không phân biệt của ai), từ chối thêm
        // để luôn còn slot thật trống cho khách khác, bất kể attacker có bao nhiêu session/IP.
        if (count($holds) >= self::MAX_HOLDS_PER_ROOM) {
            return response()->json(['message' => 'Phòng đang có quá nhiều lượt giữ chỗ tạm thời, vui lòng thử lại sau ít phút.'], 429);
        }

        // Token ngẫu nhiên phía SERVER (KHÁC session_id do client tự chọn) — bắt buộc phải xuất
        // trình đúng token này mới release() được hold vừa tạo, chặn 1 client đoán/biết session_id
        // của người khác rồi tự ý release() giả mạo hộ (session_id do client chọn không đủ tin cậy
        // để làm bằng chứng "chính chủ"). Token KHÔNG bao giờ lộ ra qua broadcastForDate() hay bất
        // kỳ response nào của client KHÁC — chỉ trả về đúng 1 lần cho chính client vừa tạo hold.
        $holdToken = Str::random(40);

        $holds[] = [
            'session_id'  => $data['session_id'],
            'timeslot_id' => (int) $data['timeslot_id'],
            'date'        => $data['date'],
            'expires_at'  => $expiresAt,
            'hold_token'  => $holdToken,
        ];

        Cache::put("time_slot_holds:{$id}", $holds, self::TTL);
        $this->broadcastForDate($id, $data['date'], $holds);

        return response()->json(['ok' => true, 'expires_at' => $expiresAt, 'hold_token' => $holdToken]);
    }

    // DELETE /api/rooms/{id}/time-slot-hold
    public function release(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'session_id'  => 'required|string|max:64',
            'timeslot_id' => 'required|integer',
            'date'        => 'required|string',
            'hold_token'  => 'required|string|max:64',
        ]);

        $holds = $this->getActiveHolds($id);

        // Xác thực "chính chủ" bằng hold_token (server-random) trước khi cho release — session_id/
        // timeslot_id/date khớp thôi CHƯA đủ, xem giải thích ở hold(). Không tìm thấy hold khớp
        // (đã hết hạn/đã release trước đó) thì coi là release thành công luôn (idempotent), không
        // cần token đúng cho trường hợp này.
        foreach ($holds as $h) {
            if ($h['session_id'] === $data['session_id']
                && (int) $h['timeslot_id'] === (int) $data['timeslot_id']
                && $h['date'] === $data['date']) {
                if (($h['hold_token'] ?? null) !== $data['hold_token']) {
                    return response()->json(['message' => 'Không có quyền huỷ giữ chỗ này.'], 403);
                }
                break;
            }
        }

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
     *
     * KHÔNG phát session_id ra ngoài — trước đây gửi kèm session_id của người đang giữ cho MỌI
     * client khác đang xem cùng phòng, để lộ session_id cho phép bất kỳ ai cũng gọi được release()
     * giả mạo hộ người khác (chỉ cần biết đúng session_id, không cần xác thực gì thêm). Các client
     * khác chỉ cần biết "ô nào đang bị giữ" để tô xám, không cần biết giữ bởi ai; mỗi client tự nhớ
     * session_id CỦA CHÍNH MÌNH (đã có ngay từ lúc gọi hold()) để tự suy ra held_by_me, không cần
     * server xác nhận lại qua broadcast.
     */
    private function broadcastForDate(string $roomId, string $date, array $allHolds): void
    {
        $holdsForDate = array_values(array_filter(
            $allHolds,
            fn ($h) => $h['date'] === $date
        ));

        // Kênh Node socket.io "room:{room_id}:{date}" — client (resources/js/ws-client.js,
        // subscribeRoomDate()) subscribe bằng data-iso-date (Y-m-d), trong khi $date ở đây đang là
        // d-m-Y (khớp $date['date'] của GET .../time-slots — xem docblock class). Gửi thẳng d-m-Y
        // vào broadcastSlotHold() sẽ join SAI kênh (không ai đang nghe), khiến broadcast không tới
        // được client nào — phải đổi sang Y-m-d NGAY TRƯỚC khi gọi, không đổi ở nơi khác (cache
        // lưu/BranchController đọc lại vẫn cần nguyên d-m-Y, chỉ riêng kênh realtime này cần ISO).
        $isoDate = \Carbon\Carbon::createFromFormat('d-m-Y', $date)->toDateString();

        app(SlotRealtimeService::class)->broadcastSlotHold($roomId, $isoDate, array_map(
            fn ($h) => ['timeslot_id' => $h['timeslot_id']],
            $holdsForDate
        ));
    }
}
