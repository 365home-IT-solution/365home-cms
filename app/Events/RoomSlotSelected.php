<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RoomSlotSelected implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $roomId;
    public $timeslotId;
    public $date;

    public function __construct($roomId, $timeslotId, $date)
    {
        $this->roomId = $roomId;
        $this->timeslotId = $timeslotId;
        $this->date = $date;
    }

    public function broadcastOn()
    {
        return new Channel('room.' . $this->roomId);
    }

    public function broadcastAs()
    {
        return 'slot.selected';
    }
}

