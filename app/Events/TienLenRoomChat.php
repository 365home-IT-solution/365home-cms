<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tin nhắn / sticker mới trong phòng Tiến lên — kênh private-tien-len.room.{roomId}, tên sự kiện "chat".
 * Mang nguyên tin nhắn (GameChatService::publicMessage). Người gửi cũng nhận — app bỏ qua tin có id đã hiển thị.
 */
class TienLenRoomChat implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $roomId, public array $message)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tien-len.room.' . $this->roomId)];
    }

    public function broadcastAs(): string
    {
        return 'chat';
    }

    public function broadcastWith(): array
    {
        return ['room_id' => $this->roomId, 'message' => $this->message];
    }
}
