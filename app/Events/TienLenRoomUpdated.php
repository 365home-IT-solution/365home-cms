<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\TienLenMatch;
use App\Models\TienLenRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phòng Tiến lên vừa đổi (có người vào/ra/sẵn sàng, bắt đầu ván, có nước đi, hết ván…). Kênh private-tien-len.room.{roomId}, tên sự kiện "updated".
 * CHỈ báo hiệu — không mang bài của ai: nhận được thì app gọi GET /api/games/tien-len/rooms/{id}?after={event_no cuối đã chạy}
 * để lấy trạng thái riêng của mình + các sự kiện mới. Xác thực kênh: POST /api/broadcasting/auth (Bearer token khách).
 */
class TienLenRoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $roomId, public string $kind)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tien-len.room.' . $this->roomId)];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        $room  = TienLenRoom::find($this->roomId);
        $match = $room?->current_match_id ? TienLenMatch::find($room->current_match_id) : null;

        return [
            'room_id'     => $this->roomId,
            'kind'        => $this->kind, // room | match | match_end | closed
            'room_status' => $room?->status,
            'match_id'    => $match?->id ?? $room?->last_match_id,
            'seq'         => $match?->seq,
            'event_count' => $match?->event_count,
        ];
    }
}
