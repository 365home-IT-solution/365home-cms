<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// Bắn khi admin khoá/mở khoá khoảng ngày cho phòng theo NGÀY (styles=2) — RoomBlockController,
// BlockTimeslotModal (xem SlotRealtimeService::broadcastDailyBlocked()). Kênh PUBLIC theo phòng,
// dùng chung channel 'timeslot-holds.{roomId}' với TimeslotHeld/Released. Trang chi tiết phòng của
// khách (product-detail.blade.php) vá TRỰC TIẾP mảng Alpine `adminBlockedRanges` — khu vực
// date-range-picker nằm trong `wire:ignore` (xem #pd-timeslots-section) nên Livewire re-render
// KHÔNG tự patch được, chỉ JS mới cập nhật được UI này mà không cần tải lại cả trang.
class RoomDailyBlockedRangesChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $productId,
        public array $blockedRanges,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('timeslot-holds.' . $this->productId)];
    }

    public function broadcastAs(): string
    {
        return 'daily-blocked-ranges-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'product_id'     => $this->productId,
            'blocked_ranges' => $this->blockedRanges,
        ];
    }
}
