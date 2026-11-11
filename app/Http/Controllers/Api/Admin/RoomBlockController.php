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
     * styles=2 — body: date_from, date_to (không cần room_time_slot_ids); biến thể phòng thêm rooms (tuỳ chọn, 1..sức
     *            chứa) = khoá MỘT PHẦN số phòng — bỏ trống hoặc = sức chứa thì khoá trọn loại phòng như trước.
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
     * GET /api/admin/rooms/{id}/block — danh sách đang khoá cho màn "Khoá phòng" (app vẽ lịch + nút mở khoá).
     * Query tuỳ chọn: month=YYYY-MM hoặc from/to (Y-m-d) để lọc; bỏ trống = mọi khoá từ hôm nay trở đi.
     * styles=2: blocked_ranges[{start,end,rooms}] (dùng nguyên start/end cho DELETE) + blocked_dates[] (từng ngày khoá trọn,
     *           end tính cả ngày cuối) + partial_dates{Y-m-d: số phòng khoá} cho biến thể khoá một phần.
     * styles=1: slots[{room_time_slot_id,label,blocked_dates[]}] + blocked_dates[] (gộp, ngày có ít nhất 1 khung bị khoá).
     */
    public function index(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $request->validate([
            'month' => 'nullable|date_format:Y-m',
            'from'  => 'nullable|date_format:Y-m-d',
            'to'    => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        if ($request->filled('month')) {
            $from = Carbon::createFromFormat('Y-m', $request->query('month'))->startOfMonth()->toDateString();
            $to   = Carbon::createFromFormat('Y-m', $request->query('month'))->endOfMonth()->toDateString();
        } else {
            $from = $request->query('from', now()->toDateString());
            $to   = $request->query('to', '9999-12-31');
        }

        $base = [
            'room'     => ['id' => $room->id, 'name' => $room->name, 'styles' => (int) $room->styles],
            'from'     => $from,
            'to'       => $to === '9999-12-31' ? null : $to,
            'capacity' => $room->roomCapacity(),
        ];

        if ((int) $room->styles === 2) {
            $config = is_array($room->room_config) ? $room->room_config : [];
            $ranges = collect($config['blocked_ranges'] ?? [])
                ->filter(fn ($r) => ! empty($r['start']) && ! empty($r['end']))
                ->map(fn ($r) => [
                    'start' => Carbon::parse($r['start'])->toDateString(),
                    'end'   => Carbon::parse($r['end'])->toDateString(),
                    'rooms' => isset($r['rooms']) ? (int) $r['rooms'] : null,
                ])
                ->filter(fn ($r) => $r['end'] >= $from && $r['start'] <= $to)
                ->sortBy('start')->values();

            $full    = [];
            $partial = [];
            foreach ($ranges as $r) {
                $isPartial = $room->is_variant && $r['rooms'] && $r['rooms'] < $room->roomCapacity();
                $day = Carbon::parse(max($r['start'], $from));
                $end = Carbon::parse(min($r['end'], $to));
                while ($day->lte($end)) {
                    $d = $day->toDateString();
                    if ($isPartial) {
                        $partial[$d] = min($room->roomCapacity(), ($partial[$d] ?? 0) + $r['rooms']);
                    } else {
                        $full[$d] = true;
                    }
                    $day->addDay();
                }
            }
            ksort($full);
            ksort($partial);

            return response()->json(['data' => $base + [
                'blocked_ranges' => $ranges->all(),
                'blocked_dates'  => array_keys($full),
                'partial_dates'  => (object) array_diff_key($partial, $full),
            ]]);
        }

        $slots = RoomTimeSlot::where('room_id', $room->id)->whereNull('date')->with('timeSlot')->get()
            ->map(fn (RoomTimeSlot $rts) => [
                'room_time_slot_id' => $rts->id,
                'label'             => $rts->timeSlot?->label ?? 'Slot #' . $rts->id,
                'blocked_dates'     => collect($rts->settings['blocked_dates'] ?? [])
                    ->filter(fn ($d) => $d >= $from && $d <= $to)->sort()->values()->all(),
            ])
            ->filter(fn ($s) => ! empty($s['blocked_dates']))->values();

        return response()->json(['data' => $base + [
            'slots'         => $slots->all(),
            'blocked_dates' => $slots->pluck('blocked_dates')->flatten()->unique()->sort()->values()->all(),
        ]]);
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
        $capacity = $room->roomCapacity();
        $data     = $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'rooms'     => "nullable|integer|min:1|max:{$capacity}",
        ], [
            'rooms.max' => "Loại phòng này chỉ có {$capacity} phòng.",
        ]);

        $range = [
            'start' => Carbon::parse($data['date_from'])->toDateString(),
            'end'   => Carbon::parse($data['date_to'])->toDateString(),
        ];

        // Biến thể phòng khoá một phần: không khoá quá số phòng đang trống (đã có khách đặt) để tránh bán lố.
        if ($room->is_variant && ! empty($data['rooms']) && (int) $data['rooms'] < $capacity) {
            $free = app(\App\Services\RoomInventory::class)->remaining(
                $room,
                Carbon::parse($range['start'])->startOfDay(),
                Carbon::parse($range['end'])->addDay()->startOfDay(),
            );

            if ((int) $data['rooms'] > $free) {
                return response()->json([
                    'message' => "Khoảng ngày này chỉ còn {$free} phòng trống (đã có khách đặt) — không khoá được {$data['rooms']} phòng.",
                    'errors'  => ['rooms' => ["Chỉ còn {$free} phòng trống."]],
                ], 422);
            }

            $range['rooms'] = (int) $data['rooms'];
        }

        $config = is_array($room->room_config) ? $room->room_config : [];
        $ranges = $config['blocked_ranges'] ?? [];
        $ranges[] = $range;
        $config['blocked_ranges'] = array_values($ranges);
        $room->update(['room_config' => $config]);

        app(SlotRealtimeService::class)->broadcastDailyBlocked((string) $room->id, $config['blocked_ranges']);

        return response()->json([
            'message' => isset($range['rooms']) ? "Đã khoá {$range['rooms']}/{$capacity} phòng." : 'Đã khoá khoảng thời gian.',
            'data'    => $range + ['rooms' => $room->is_variant ? $capacity : null, 'capacity' => $capacity],
        ]);
    }

    // Gỡ khoá theo PHẦN GIAO: mọi khoảng đang khoá chồng lên [date_from, date_to] (tính cả 2 đầu) bị cắt bỏ đúng các ngày đó,
    // phần còn lại trước/sau được giữ thành khoảng riêng (giữ nguyên rooms nếu là khoá một phần). Trước đây bắt buộc start/end
    // khớp CHÍNH XÁC 1 khoảng → app chọn mở 1 ngày / 1 phần khoảng hoặc khoảng gộp nhiều khoảng đều nhận 404.
    private function unblockDailyRange(Request $request, Product $room): JsonResponse
    {
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to   = Carbon::parse($data['date_to'])->startOfDay();

        $config = is_array($room->room_config) ? $room->room_config : [];
        $ranges = $config['blocked_ranges'] ?? [];

        $remaining = [];
        $touched   = false;

        foreach ($ranges as $range) {
            if (empty($range['start']) || empty($range['end'])) {
                $remaining[] = $range;
                continue;
            }

            $rStart = Carbon::parse($range['start'])->startOfDay();
            $rEnd   = Carbon::parse($range['end'])->startOfDay();

            if ($rEnd->lt($from) || $rStart->gt($to)) {
                $remaining[] = $range;
                continue;
            }

            $touched = true;

            if ($rStart->lt($from)) {
                $remaining[] = ['start' => $rStart->toDateString(), 'end' => $from->copy()->subDay()->toDateString()] + \Illuminate\Support\Arr::except($range, ['start', 'end']);
            }
            if ($rEnd->gt($to)) {
                $remaining[] = ['start' => $to->copy()->addDay()->toDateString(), 'end' => $rEnd->toDateString()] + \Illuminate\Support\Arr::except($range, ['start', 'end']);
            }
        }

        if (! $touched) {
            return response()->json(['message' => 'Khoảng ngày này hiện không bị khoá.'], 404);
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
        if (! $room || (! $user->isSuperAdmin() && ! $this->userCanAccessRoom($user, $room))) {
            return null;
        }

        return $room;
    }

    // allowedCategoryIds() rỗng = admin không bị giới hạn chi nhánh cụ thể trong đối tác (vd chủ
    // đối tác) → chỉ cần đúng partner_id là đủ.
    private function userCanAccessRoom(User $user, Product $room): bool
    {
        if ($room->partner_id !== $user->partner_id) {
            return false;
        }

        $allowedCategoryIds = $user->allowedCategoryIds();
        if (empty($allowedCategoryIds)) {
            return true;
        }

        return $room->categories()->whereIn('categories.id', $allowedCategoryIds)->exists();
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
