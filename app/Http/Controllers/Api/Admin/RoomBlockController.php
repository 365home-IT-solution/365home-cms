<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SlotRealtimeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * Khoá/mở khoá lịch phòng ở admin — bản REST API của Filament `Modules\Book\Livewire\
 * BlockTimeslotModal`, tái dùng ĐÚNG 2 cơ chế lưu trữ hiện có (khác nhau theo `products.styles`)
 * để không phá vỡ logic tính giá/hiển thị đang đọc 2 nơi này:
 *  - styles = 1 (phòng theo khung giờ): ghi vào `room_time_slots.settings.blocked_dates` — theo
 *    TỪNG bản ghi `room_time_slots` (bản mặc định `date IS NULL` của phòng) + từng ngày cụ thể.
 *  - styles = 2 (phòng theo ngày): ghi vào `products.room_config.blocked_ranges` — mảng các
 *    khoảng `{start,end}` áp dụng cho toàn phòng.
 * Sau khi ghi DB, gọi `SlotRealtimeService` (bắn HTTP nội bộ tới Node WS server) để đồng bộ
 * realtime với các client đang xem lịch phòng — đúng cách Filament đang làm.
 */
class RoomBlockController extends Controller
{
    /**
     * POST /api/admin/rooms/{id}/block
     * styles=1 — body: room_time_slot_ids[] (id của room_time_slots, PHẢI thuộc đúng phòng),
     *            date_from, date_to (Y-m-d, date_to >= date_from).
     * styles=2 — body: date_from, date_to (không cần room_time_slot_ids).
     */
    public function block(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ((int) $room->styles === 2) {
            return $this->blockDailyRange($request, $room);
        }

        return $this->blockTimeslotDates($request, $room);
    }

    /**
     * DELETE /api/admin/rooms/{id}/block
     * Cùng body shape với block() — gỡ đúng phần đã khoá khớp với các giá trị truyền vào.
     */
    public function unblock(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ((int) $room->styles === 2) {
            return $this->unblockDailyRange($request, $room);
        }

        return $this->unblockTimeslotDates($request, $room);
    }

    // ── styles = 1 ───────────────────────────────────────────────────────────

    private function blockTimeslotDates(Request $request, Product $room): JsonResponse
    {
        $data = $request->validate([
            'room_time_slot_ids'   => 'required|array|min:1',
            'room_time_slot_ids.*' => 'integer',
            'date_from'            => 'required|date',
            'date_to'              => 'required|date|after_or_equal:date_from',
        ]);

        $rtsIds = $this->validateOwnedRoomTimeSlots($room, $data['room_time_slot_ids']);
        if ($rtsIds instanceof JsonResponse) {
            return $rtsIds;
        }

        $blockedDates = $this->buildDateRange($data['date_from'], $data['date_to']);

        DB::transaction(function () use ($rtsIds, $blockedDates) {
            foreach (RoomTimeSlot::whereIn('id', $rtsIds)->get() as $rts) {
                $settings = $rts->settings ?? [];
                $existing = $settings['blocked_dates'] ?? [];
                $merged   = array_values(array_unique(array_merge($existing, $blockedDates)));
                sort($merged);
                $settings['blocked_dates'] = $merged;
                $rts->update(['settings' => $settings]);
            }
        });

        app(SlotRealtimeService::class)->broadcastBlockedRange(
            (string) $room->id,
            $blockedDates,
            $rtsIds,
            'blocked',
        );

        return response()->json([
            'message' => 'Đã khoá ' . count($blockedDates) . ' ngày cho ' . count($rtsIds) . ' khung giờ.',
        ]);
    }

    private function unblockTimeslotDates(Request $request, Product $room): JsonResponse
    {
        $data = $request->validate([
            'room_time_slot_ids'   => 'required|array|min:1',
            'room_time_slot_ids.*' => 'integer',
            'date_from'            => 'required|date',
            'date_to'              => 'required|date|after_or_equal:date_from',
        ]);

        $rtsIds = $this->validateOwnedRoomTimeSlots($room, $data['room_time_slot_ids']);
        if ($rtsIds instanceof JsonResponse) {
            return $rtsIds;
        }

        $datesToRemove = $this->buildDateRange($data['date_from'], $data['date_to']);

        DB::transaction(function () use ($rtsIds, $datesToRemove) {
            foreach (RoomTimeSlot::whereIn('id', $rtsIds)->get() as $rts) {
                $settings = $rts->settings ?? [];
                $settings['blocked_dates'] = array_values(
                    array_diff($settings['blocked_dates'] ?? [], $datesToRemove)
                );
                $rts->update(['settings' => $settings]);
            }
        });

        app(SlotRealtimeService::class)->broadcastBlockedRange(
            (string) $room->id,
            $datesToRemove,
            $rtsIds,
            'available',
        );

        return response()->json(['message' => 'Đã mở khoá.']);
    }

    // ── styles = 2 ───────────────────────────────────────────────────────────

    private function blockDailyRange(Request $request, Product $room): JsonResponse
    {
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $config = is_array($room->room_config) ? $room->room_config : [];
        $ranges = $config['blocked_ranges'] ?? [];
        $ranges[] = [
            'start' => Carbon::parse($data['date_from'])->toDateString(),
            'end'   => Carbon::parse($data['date_to'])->toDateString(),
        ];
        $config['blocked_ranges'] = array_values($ranges);
        $room->update(['room_config' => $config]);

        app(SlotRealtimeService::class)->broadcastDailyBlocked((string) $room->id, $config['blocked_ranges']);

        return response()->json(['message' => 'Đã khoá khoảng thời gian.']);
    }

    // Gỡ đúng khoảng có start/end khớp CHÍNH XÁC với request — không xử lý gỡ 1 phần của 1 khoảng
    // (khớp hành vi đơn giản của Filament, xoá theo index trong danh sách hiển thị).
    private function unblockDailyRange(Request $request, Product $room): JsonResponse
    {
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $start = Carbon::parse($data['date_from'])->toDateString();
        $end   = Carbon::parse($data['date_to'])->toDateString();

        $config = is_array($room->room_config) ? $room->room_config : [];
        $ranges = $config['blocked_ranges'] ?? [];

        $remaining = array_values(array_filter(
            $ranges,
            fn ($range) => ! (($range['start'] ?? null) === $start && ($range['end'] ?? null) === $end)
        ));

        if (count($remaining) === count($ranges)) {
            return response()->json(['message' => 'Không tìm thấy khoảng thời gian đang khoá khớp với dữ liệu truyền lên.'], 404);
        }

        $config['blocked_ranges'] = $remaining;
        $room->update(['room_config' => $config]);

        app(SlotRealtimeService::class)->broadcastDailyBlocked((string) $room->id, $remaining);

        return response()->json(['message' => 'Đã mở khoá khoảng thời gian.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function visibleRoom(User $user, string $id): ?Product
    {
        $room = Product::find($id);
        if (! $room || (! $user->isSuperAdmin() && $room->partner_id !== $user->partner_id)) {
            return null;
        }

        return $room;
    }

    /** @return array<int>|JsonResponse */
    private function validateOwnedRoomTimeSlots(Product $room, array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $ownedIds = RoomTimeSlot::whereIn('id', $ids)->where('room_id', $room->id)->pluck('id')->all();
        $invalid  = array_diff($ids, $ownedIds);

        if (! empty($invalid)) {
            return response()->json([
                'message' => 'room_time_slot_ids không thuộc phòng này: ' . implode(', ', $invalid) . '.',
            ], 422);
        }

        return $ids;
    }

    private function buildDateRange(string $from, string $to): array
    {
        $dates   = [];
        $current = Carbon::parse($from)->startOfDay();
        $end     = Carbon::parse($to)->startOfDay();

        while ($current->lte($end)) {
            $dates[] = $current->toDateString();
            $current->addDay();
        }

        return $dates;
    }
}
