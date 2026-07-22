<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// Bắn ngay khi 1 admin bấm chọn 1 ô (khung giờ + ngày) trên lưới đặt phòng — ShouldBroadcastNow
// (không qua hàng đợi) vì hold chỉ có ý nghĩa khi tới nơi NGAY LẬP TỨC, chờ queue worker xử lý là
// vô nghĩa với 1 sự kiện tồn tại vài giây tới vài phút.
class TimeslotHeld implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $productId,
        public int $roomTimeSlotId,
        public int $timeslotId,
        public string $date,
        public string $userId,
        public string $userName,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin-timeslot-holds'),
            // Kênh PUBLIC riêng theo từng phòng — để trang đặt phòng CỦA KHÁCH HÀNG (không đăng
            // nhập được vào kênh private admin ở trên) cũng nhận real-time ngay, không cần đợi
            // khách tự thao tác/F5 mới thấy khung giờ bị khóa (xem ProductDetail::echo listener).
            new Channel('timeslot-holds.' . $this->productId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'held';
    }

    public function broadcastWith(): array
    {
        return [
            'product_id'        => $this->productId,
            'room_time_slot_id' => $this->roomTimeSlotId,
            'timeslot_id'       => $this->timeslotId,
            'date'              => $this->date,
            'user_id'           => $this->userId,
            'user_name'         => $this->userName,
        ];
    }
}
