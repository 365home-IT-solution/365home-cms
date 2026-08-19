<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlotRealtimeService
{
    public function broadcastBooked(string $roomId, string $date, array $slotIds): void
    {
        $this->push($roomId, $date, $slotIds, 'pending');
    }

    /**
     * Broadcast ĐÚNG trạng thái đơn hiện tại (không chỉ nhị phân booked/available) cho 1 nhóm khung
     * giờ x ngày — dùng khi đơn đổi trạng thái (paid/deposit/cancelled/...) để lưới lịch phòng
     * (GET .../rooms/{id}/time-slots, field `status` mỗi ô) luôn khớp đúng với những gì API đó sẽ
     * trả nếu gọi lại, không cần đợi F5. Xem OrderObserver::broadcastSlotStatusChanged().
     */
    public function broadcastStatusChanged(string $roomId, string $date, array $slotIds, string $status): void
    {
        $this->push($roomId, $date, $slotIds, $status);
    }

    public function broadcastReleased(string $roomId, string $date): void
    {
        $this->push($roomId, $date, [], 'available');
    }

    /**
     * Giải phóng ĐÚNG các khung giờ cụ thể (khác broadcastReleased() — hàm đó không nhận slot_ids,
     * ý nghĩa mơ hồ nếu ngày đó còn khung giờ KHÁC vẫn đang bị chiếm bởi đơn khác). Dùng khi admin
     * sửa đơn đổi khung giờ (xoá items cũ, tạo items mới) — các khung giờ cũ cần báo lại "available"
     * chính xác từng ô, không đụng tới các khung giờ khác cùng ngày của phòng.
     */
    public function broadcastSlotsAvailable(string $roomId, string $date, array $slotIds): void
    {
        $this->push($roomId, $date, $slotIds, 'available');
    }

    public function broadcastDailyBooked(string $roomId, string $checkin, string $checkout): void
    {
        $this->pushDaily($roomId, $checkin, $checkout, 'booked');
    }

    /**
     * Giải phóng 1 khoảng ngày đã đặt trước đó (dùng khi admin sửa đơn daily đổi checkin/checkout —
     * khoảng ngày CŨ cần báo lại "còn trống"). LƯU Ý: gửi kèm 'status' => 'available' trong CÙNG
     * payload /internal/daily-booked mà broadcastDailyBooked() đang dùng (field 'status' là field
     * MỚI, thêm thuần tuý — không phá payload cũ vì các consumer hiện tại chỉ đọc room_id/checkin/
     * checkout). Server WebSocket nội bộ (Node, ngoài repo này) CẦN được cập nhật để đọc field
     * 'status' và xử lý đúng 2 nhánh — nếu chưa cập nhật, lệnh gọi này sẽ không có tác dụng (hoặc
     * tác dụng sai) cho tới khi phía đó được vá theo.
     */
    public function broadcastDailyReleased(string $roomId, string $checkin, string $checkout): void
    {
        $this->pushDaily($roomId, $checkin, $checkout, 'available');
    }

    private function pushDaily(string $roomId, string $checkin, string $checkout, string $status): void
    {
        $url = rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
        $key = config('services.websocket.internal_key', '');

        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $key])
                ->timeout(2)
                ->post("{$url}/internal/daily-booked", [
                    'room_id'  => $roomId,
                    'checkin'  => $checkin,
                    'checkout' => $checkout,
                    'status'   => $status,
                ])->throw();
        } catch (\Throwable $e) {
            Log::warning('WS daily booked push failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Broadcast tô đen / gỡ tô đen nhiều ngày cùng lúc (style 1 - theo khung giờ).
     *
     * @param  array   $slotIds  RoomTimeSlot IDs bị tô đen (rỗng = toàn bộ slot của phòng)
     * @param  string  $status   'blocked' | 'available'
     */
    public function broadcastBlockedRange(string $roomId, array $dates, array $slotIds = [], string $status = 'blocked'): void
    {
        $url = rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
        $key = config('services.websocket.internal_key', '');

        if (empty($url) || empty($dates)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $key])
                ->timeout(3)
                ->post("{$url}/internal/slot-blocked-range", [
                    'room_id'  => $roomId,
                    'dates'    => $dates,
                    'slot_ids' => $slotIds,
                    'status'   => $status,
                ])->throw();
        } catch (\Throwable $e) {
            Log::warning('WS slot-blocked-range push failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Broadcast cập nhật blocked_ranges cho phòng theo ngày (style 2).
     *
     * @param  array  $blockedRanges  Danh sách khoảng khóa hiện tại [['start'=>..,'end'=>..], ...]
     */
    public function broadcastDailyBlocked(string $roomId, array $blockedRanges): void
    {
        $url = rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
        $key = config('services.websocket.internal_key', '');

        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $key])
                ->timeout(2)
                ->post("{$url}/internal/daily-blocked", [
                    'room_id'        => $roomId,
                    'blocked_ranges' => $blockedRanges,
                ])->throw();
        } catch (\Throwable $e) {
            Log::warning('WS daily-blocked push failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }

    public function broadcastDailyHold(string $roomId, array $holds): void
    {
        $url = rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
        $key = config('services.websocket.internal_key', '');

        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $key])
                ->timeout(2)
                ->post("{$url}/internal/daily-hold-update", [
                    'room_id' => $roomId,
                    'holds'   => $holds,
                ])->throw();
        } catch (\Throwable $e) {
            Log::warning('WS daily hold push failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Hold "đang chọn" cho phòng theo khung giờ (style 1) — xem TimeSlotHoldController. Bắn theo
     * ĐÚNG 1 ngày (cùng channel room:{room_id}:{date} dùng cho slot.updated ở trên) — $holds là
     * danh sách {session_id, timeslot_id} còn giữ của RIÊNG ngày này, có thể rỗng (vừa release hold
     * cuối cùng của ngày đó, vẫn cần bắn để client biết ngày này hết hold).
     */
    public function broadcastSlotHold(string $roomId, string $date, array $holds): void
    {
        $url = rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
        $key = config('services.websocket.internal_key', '');

        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $key])
                ->timeout(2)
                ->post("{$url}/internal/slot-hold-update", [
                    'room_id' => $roomId,
                    'date'    => $date,
                    'holds'   => $holds,
                ])->throw();
        } catch (\Throwable $e) {
            Log::warning('WS slot hold push failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }

    private function push(string $roomId, string $date, array $slotIds, string $status): void
    {
        $url = rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
        $key = config('services.websocket.internal_key', '');

        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $key])
                ->timeout(2)
                ->post("{$url}/internal/slot-update", [
                    'room_id'  => $roomId,
                    'date'     => $date,
                    'slot_ids' => $slotIds,
                    'status'   => $status,
                ])->throw();
        } catch (\Throwable $e) {
            Log::warning('WS slot push failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }
}
