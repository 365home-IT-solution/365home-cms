<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * Gộp XEM + SỬA giá khung giờ (room_time_slots.price/over_night/checkin/checkout) VÀ điều kiện
 * giảm giá (full_booking_discount/bulk_discount_rules/room_config/deposit_1_night/deposit_multi_night/
 * deposit_min_nights/default_checkin/default_checkout) trong CÙNG 1 cặp API — cho 1 hoặc NHIỀU
 * phòng cùng lúc (room_ids[]).
 *
 * Khác với các API tách rời đã có trước đó:
 *  - GET/POST  /api/admin/products/{id}/time-slots      (RoomTimeSlotController — chỉ giá, 1 phòng)
 *  - PATCH     /api/admin/rooms/{id}/booking-settings    (ProductController — chỉ giảm giá, 1 phòng)
 *  - POST      /api/admin/products/discount-settings     (ProductController — chỉ giảm giá, nhiều phòng)
 * 2 API dưới đây vẫn dùng CHUNG logic ghi (updateOrCreate cho room_time_slots, merge nông cho
 * room_config) — không phải cài lại từ đầu, chỉ gộp lại cho tiện gọi 1 lần thay vì nhiều API.
 *
 * Field áp dụng theo styles (xem docblock ProductController::updateBookingSettings()):
 *  - full_booking_discount, bulk_discount_rules : styles=1 (khung giờ)
 *  - deposit_1_night/deposit_multi_night/deposit_min_nights/default_checkin/default_checkout : styles=2
 *  - room_config, time_slots : cả 2 styles
 */
class RoomPricingController extends Controller
{
    private const DISCOUNT_FIELDS = [
        'full_booking_discount', 'bulk_discount_rules', 'room_config',
        'deposit_1_night', 'deposit_multi_night', 'deposit_min_nights',
        'default_checkin', 'default_checkout',
    ];

    /**
     * GET /api/admin/rooms/pricing?room_ids[]=...&room_ids[]=...
     * Trả giá khung giờ + điều kiện giảm giá hiện tại cho từng phòng trong room_ids[].
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_ids'   => 'required|array|min:1',
            'room_ids.*' => 'string',
        ]);

        $rooms = $this->visibleRoomsQuery($request->user())
            ->whereIn('id', $data['room_ids'])
            ->with(['roomTimeSlots' => fn ($q) => $q->whereNull('date')->with('timeSlot')])
            ->get();

        $foundIds = $rooms->pluck('id')->all();
        $notFound = array_values(array_diff($data['room_ids'], $foundIds));

        return response()->json([
            'data'      => $rooms->map(fn (Product $room) => $this->toItem($room))->values(),
            'not_found' => $notFound,
        ]);
    }

    /**
     * PATCH /api/admin/rooms/pricing
     * Áp CÙNG 1 bộ giá khung giờ / điều kiện giảm giá cho 1 hoặc nhiều phòng trong room_ids[].
     * Cần ít nhất 'time_slots' HOẶC 1 field giảm giá. Phòng ngoài phạm vi đối tác (user thường) hoặc
     * không tồn tại bị bỏ qua, trả về trong 'skipped' thay vì lỗi cả request.
     *
     * Body:
     *  - room_ids (required)      : mảng id phòng cần áp dụng
     *  - time_slots                : mảng { timeslot_id (required), price, over_night, checkin,
     *                                checkout } — updateOrCreate theo (room_id, timeslot_id, date=null)
     *                                cho TỪNG phòng trong room_ids (tạo mới nếu phòng đó chưa có
     *                                đúng timeslot_id này).
     *  - full_booking_discount, bulk_discount_rules, room_config, deposit_1_night,
     *    deposit_multi_night, deposit_min_nights, default_checkin, default_checkout : như
     *    ProductController::updateBookingSettings() — 'room_config' merge NÔNG với giá trị hiện có
     *    của TỪNG phòng (không ghi đè toàn bộ), giữ nguyên 'blocked_ranges' nếu có.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_ids'                       => 'required|array|min:1',
            'room_ids.*'                      => 'string',

            'time_slots'                      => 'nullable|array',
            'time_slots.*.timeslot_id'        => 'required_with:time_slots|integer|exists:time_slots,id',
            'time_slots.*.price'              => 'nullable|integer|min:0',
            'time_slots.*.over_night'         => 'nullable|boolean',
            'time_slots.*.checkin'            => 'nullable|date_format:H:i',
            'time_slots.*.checkout'           => 'nullable|date_format:H:i',

            'full_booking_discount'           => 'nullable|string|max:50',
            'bulk_discount_rules'             => 'nullable|array',
            'bulk_discount_rules.*.slots'     => 'required_with:bulk_discount_rules|integer|min:1',
            'bulk_discount_rules.*.discount'  => 'required_with:bulk_discount_rules|numeric|min:0|max:100',
            'room_config'                     => 'nullable|array',
            'room_config.max_free_guests'     => 'nullable|integer|min:0',
            'room_config.extra_guest_fee'     => 'nullable|numeric|min:0',
            'deposit_1_night'                 => 'nullable|integer|min:0|max:100',
            'deposit_multi_night'             => 'nullable|integer|min:0|max:100',
            'deposit_min_nights'              => 'nullable|integer|min:1',
            'default_checkin'                 => 'nullable|date_format:H:i',
            'default_checkout'                => 'nullable|date_format:H:i',
        ]);

        $hasTimeSlots = ! empty($data['time_slots'] ?? []);
        $hasDiscount  = ! empty(array_intersect(array_keys($data), self::DISCOUNT_FIELDS));

        if (! $hasTimeSlots && ! $hasDiscount) {
            return response()->json(['message' => 'Cần nhập ít nhất time_slots hoặc 1 field điều kiện giảm giá.'], 422);
        }

        $rooms = $this->visibleRoomsQuery($request->user())->whereIn('id', $data['room_ids'])->get();

        $foundIds = $rooms->pluck('id')->all();
        $skipped  = array_values(array_diff($data['room_ids'], $foundIds));

        DB::transaction(function () use ($rooms, $data, $hasTimeSlots, $hasDiscount) {
            foreach ($rooms as $room) {
                if ($hasTimeSlots) {
                    foreach ($data['time_slots'] as $row) {
                        RoomTimeSlot::updateOrCreate(
                            ['room_id' => $room->id, 'timeslot_id' => $row['timeslot_id'], 'date' => null],
                            [
                                'price'      => $row['price'] ?? null,
                                'over_night' => $row['over_night'] ?? false,
                                'checkin'    => $row['checkin'] ?? null,
                                'checkout'   => $row['checkout'] ?? null,
                                'status'     => 'available',
                            ]
                        );
                    }
                }

                if ($hasDiscount) {
                    $update = collect($data)->only(self::DISCOUNT_FIELDS)->toArray();

                    if (array_key_exists('room_config', $update)) {
                        $existing            = is_array($room->room_config) ? $room->room_config : [];
                        $update['room_config'] = array_merge($existing, $update['room_config']);
                    }

                    $room->update($update);
                }
            }
        });

        return response()->json([
            'message' => 'Đã cập nhật.',
            'updated' => $foundIds,
            'skipped' => $skipped,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function visibleRoomsQuery(User $user): Builder
    {
        $query = Product::query();

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        return $query;
    }

    private function toItem(Product $room): array
    {
        return [
            'room_id' => $room->id,
            'name'    => $room->name,
            'styles'  => (int) $room->styles,

            'time_slots' => $room->roomTimeSlots->map(fn (RoomTimeSlot $rts) => [
                'room_time_slot_id' => $rts->id,
                'timeslot_id'       => $rts->timeslot_id,
                'price'             => $rts->price,
                'over_night'        => (bool) $rts->over_night,
                'checkin'           => $rts->checkin,
                'checkout'          => $rts->checkout,
                'time_slot'         => $rts->timeSlot ? [
                    'id'         => $rts->timeSlot->id,
                    'start_time' => $rts->timeSlot->start_time,
                    'end_time'   => $rts->timeSlot->end_time,
                    'label'      => $rts->timeSlot->label,
                ] : null,
            ])->values(),

            'discount_settings' => [
                'full_booking_discount' => $room->full_booking_discount,
                'bulk_discount_rules'   => $room->bulk_discount_rules,
                'room_config'           => $room->room_config,
                'deposit_1_night'       => $room->deposit_1_night,
                'deposit_multi_night'   => $room->deposit_multi_night,
                'deposit_min_nights'    => $room->deposit_min_nights,
                'default_checkin'       => $room->default_checkin,
                'default_checkout'      => $room->default_checkout,
            ],
        ];
    }
}
