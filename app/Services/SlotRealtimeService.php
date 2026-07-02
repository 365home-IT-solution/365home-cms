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
                ]);
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
                ]);
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
