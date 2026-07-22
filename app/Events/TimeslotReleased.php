<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TimeslotReleased implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $productId,
        public int $roomTimeSlotId,
        public int $timeslotId,
        public string $date,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin-timeslot-holds'),
            new Channel('timeslot-holds.' . $this->productId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'released';
    }

    public function broadcastWith(): array
    {
        return [
            'product_id'        => $this->productId,
            'room_time_slot_id' => $this->roomTimeSlotId,
            'timeslot_id'       => $this->timeslotId,
            'date'              => $this->date,
        ];
    }
}
