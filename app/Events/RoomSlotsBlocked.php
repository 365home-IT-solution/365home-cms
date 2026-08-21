<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// Bắn khi admin tô đen / gỡ tô đen 1 hoặc nhiều ngày cho 1 nhóm khung giờ (RoomBlockController,
// BlockTimeslotModal, SettingBook, PruneExpiredBlockedTimeslots — xem
// SlotRealtimeService::broadcastBlockedRange()) — kênh PUBLIC theo phòng, DÙNG CHUNG channel với
// TimeslotHeld/Released ('timeslot-holds.{roomId}', resources/js/echo-client.js đã subscribe sẵn
// cho mọi trang đặt phòng của khách) để vá TRỰC TIẾP đúng các ô bị ảnh hưởng thay vì ép Livewire
// re-render toàn bộ bảng (khác với resources/js/ws-client.js — event Node WS `slot.updated` cùng
// nguồn dữ liệu vẫn còn dùng cho thay đổi giá/khuyến mãi/đặt phòng thật, KHÔNG đụng ở đây, xem
// ws-client.js::scheduleDispatch()).
class RoomSlotsBlocked implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $productId,
        public array $dates,
        public array $timeslotIds,
        public string $status,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('timeslot-holds.' . $this->productId)];
    }

    public function broadcastAs(): string
    {
        return 'slot-blocked-range';
    }

    public function broadcastWith(): array
    {
        return [
            'product_id'   => $this->productId,
            'dates'        => $this->dates,
            'timeslot_ids' => $this->timeslotIds,
            'status'       => $this->status,
        ];
    }
}
