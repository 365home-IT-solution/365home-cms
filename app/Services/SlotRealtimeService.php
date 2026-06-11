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

    public function broadcastReleased(string $roomId, string $date): void
    {
        $this->push($roomId, $date, [], 'available');
    }

    public function broadcastDailyBooked(string $roomId, string $checkin, string $checkout): void
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
                ]);
        } catch (\Throwable $e) {
            Log::warning('WS daily booked push failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
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
                ]);
        } catch (\Throwable $e) {
            Log::warning('WS daily hold push failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
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
                ]);
        } catch (\Throwable $e) {
            Log::warning('WS slot push failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }
}
