<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * Gộp XEM + SỬA giá khung giờ (room_time_slots.price/over_night) VÀ điều kiện giảm giá
 * (full_booking_discount/bulk_discount_rules/room_config/deposit_1_night/deposit_multi_night/
 * deposit_min_nights/default_checkin/default_checkout) của 1 phòng trong CÙNG 1 cặp API.
 *
 * Khác với các API tách rời đã có trước đó (vẫn giữ nguyên, không xoá):
 *  - GET/POST  /api/admin/products/{id}/time-slots      (RoomTimeSlotController — chỉ giá)
 *  - PATCH     /api/admin/rooms/{id}/booking-settings    (ProductController — chỉ giảm giá)
 *  - POST      /api/admin/products/discount-settings     (ProductController — chỉ giảm giá, nhiều phòng)
 * API dưới đây dùng CHUNG logic ghi (updateOrCreate cho room_time_slots, merge nông cho
 * room_config) — không cài lại từ đầu, chỉ gộp lại cho tiện gọi 1 lần thay vì nhiều API.
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
     * GET /api/admin/rooms/{id}/pricing
     * Trả giá khung giờ + điều kiện giảm giá hiện tại của 1 phòng.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);

        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $room->load(['roomTimeSlots' => fn ($q) => $q->whereNull('date')]);

        return response()->json($this->toItem($room));
    }

    /**
     * PATCH /api/admin/rooms/{id}/pricing
     * Cần ít nhất 'time_slots' HOẶC 1 field điều kiện giảm giá.
     *
     * Body:
     *  - time_slots : mảng { timeslot_id (required), price, over_night, checkin, checkout } —
     *    updateOrCreate theo (room_id, timeslot_id, date=null) — tạo mới nếu phòng chưa có đúng
     *    timeslot_id này.
     *  - full_booking_discount, bulk_discount_rules, room_config, deposit_1_night,
     *    deposit_multi_night, deposit_min_nights, default_checkin, default_checkout : như
     *    ProductController::updateBookingSettings() — 'room_config' merge NÔNG với giá trị hiện có
     *    (không ghi đè toàn bộ), giữ nguyên 'blocked_ranges' nếu có.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);

        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $data = $request->validate([
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

        DB::transaction(function () use ($room, $data, $hasTimeSlots, $hasDiscount) {
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
                    $existing              = is_array($room->room_config) ? $room->room_config : [];
                    $update['room_config'] = array_merge($existing, $update['room_config']);
                }

                $room->update($update);
            }
        });

        $room->load(['roomTimeSlots' => fn ($q) => $q->whereNull('date')]);

        return response()->json($this->toItem($room->fresh(['roomTimeSlots' => fn ($q) => $q->whereNull('date')])));
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

    private function toItem(Product $room): array
    {
        return [
            'room' => [
                'id'     => $room->id,
                'name'   => $room->name,
                'styles' => (int) $room->styles,
            ],

            'time_slots' => $room->roomTimeSlots->map(fn (RoomTimeSlot $rts) => [
                'room_time_slot_id' => $rts->id,
                'timeslot_id'       => $rts->timeslot_id,
                'price'             => $rts->price,
                'over_night'        => (bool) $rts->over_night,
            ])->values(),

            // deposit_*/default_checkin/default_checkout CHỈ có ý nghĩa với phòng theo ngày (styles=2
            // — xem docblock ProductController::updateBookingSettings()), nên phòng styles=1 (khung
            // giờ) không trả các key này luôn (không phải trả null) để tránh gây hiểu nhầm là có tác
            // dụng với phòng khung giờ.
            'discount_settings' => (int) $room->styles === 2
                ? [
                    'full_booking_discount' => $room->full_booking_discount,
                    'bulk_discount_rules'   => $room->bulk_discount_rules,
                    'room_config'           => $room->room_config,
                    'deposit_1_night'       => $room->deposit_1_night,
                    'deposit_multi_night'   => $room->deposit_multi_night,
                    'deposit_min_nights'    => $room->deposit_min_nights,
                    'default_checkin'       => $room->default_checkin,
                    'default_checkout'      => $room->default_checkout,
                ]
                : [
                    'full_booking_discount' => $room->full_booking_discount,
                    'bulk_discount_rules'   => $room->bulk_discount_rules,
                    'room_config'           => $room->room_config,
                ],
        ];
    }
}
