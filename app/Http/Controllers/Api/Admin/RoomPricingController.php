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
     * POST /api/admin/rooms/{id}/pricing
     * Cần ít nhất 'time_slots', 'price' HOẶC 1 field điều kiện giảm giá.
     *
     * Body:
     *  - time_slots : mảng { timeslot_id (required), price, over_night, checkin, checkout } —
     *    updateOrCreate theo (room_id, timeslot_id, date=null) — tạo mới nếu phòng chưa có đúng
     *    timeslot_id này. Chỉ áp dụng phòng styles=1 (khung giờ).
     *  - price : giá phòng cơ bản (cột products.price) — CHỈ có ý nghĩa với phòng styles=2 (theo
     *    ngày/đêm, dùng làm giá gốc/đêm ở BuildsRoomBooking); phòng styles=1 định giá theo TỪNG
     *    khung giờ (time_slots[].price ở trên) nên gửi 'price' không có tác dụng gì với phòng đó.
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

            'price'                           => 'nullable|numeric|min:0',

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
        $hasPrice     = array_key_exists('price', $data);
        $hasDiscount  = ! empty(array_intersect(array_keys($data), self::DISCOUNT_FIELDS));

        if (! $hasTimeSlots && ! $hasPrice && ! $hasDiscount) {
            return response()->json(['message' => 'Cần nhập ít nhất time_slots, price hoặc 1 field điều kiện giảm giá.'], 422);
        }

        DB::transaction(function () use ($room, $data, $hasTimeSlots, $hasPrice, $hasDiscount) {
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

            if ($hasPrice) {
                $room->update(['price' => $data['price']]);
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

    /**
     * DELETE /api/admin/rooms/{id}/pricing/bulk-discount-rules
     * Xoá điều kiện giảm giá theo SỐ KHUNG GIỜ (products.bulk_discount_rules → null) — chỉ áp dụng
     * phòng styles=1. Không đụng tới full_booking_discount/room_config (xoá riêng nếu cần bằng
     * POST .../pricing với field đó = null).
     */
    public function deleteBulkDiscountRules(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);

        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ((int) $room->styles !== 1) {
            return response()->json(['message' => 'bulk_discount_rules chỉ áp dụng cho phòng theo khung giờ (styles=1).'], 422);
        }

        $room->update(['bulk_discount_rules' => null]);

        $room->load(['roomTimeSlots' => fn ($q) => $q->whereNull('date')]);

        return response()->json($this->toItem($room));
    }

    /**
     * DELETE /api/admin/rooms/{id}/pricing/bulk-discount-rules/{ruleId}
     * Xoá 1 rule trong bulk_discount_rules — ruleId LÀ 'id' trả về ở discount_settings.bulk_discount_rules
     * (xem mapBulkDiscountRules(), vị trí 1-based tại lần GET/POST gần nhất — gọi lại GET trước nếu
     * đã lâu chưa xem, tránh xoá nhầm rule khác do mảng đã bị ghi đè ở nơi khác).
     */
    public function deleteBulkDiscountRule(Request $request, string $id, int $ruleId): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);

        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ((int) $room->styles !== 1) {
            return response()->json(['message' => 'bulk_discount_rules chỉ áp dụng cho phòng theo khung giờ (styles=1).'], 422);
        }

        $rules = $room->bulk_discount_rules ?? [];

        if ($ruleId < 1 || $ruleId > count($rules)) {
            return response()->json(['message' => 'Không tìm thấy rule giảm giá này.'], 404);
        }

        unset($rules[$ruleId - 1]);
        $rules = array_values($rules);

        $room->update(['bulk_discount_rules' => empty($rules) ? null : $rules]);

        $room->load(['roomTimeSlots' => fn ($q) => $q->whereNull('date')]);

        return response()->json($this->toItem($room));
    }

    /**
     * DELETE /api/admin/rooms/{id}/pricing/time-slots/{timeslotId}
     * Gỡ HẲN 1 khung giờ ra khỏi phòng — xoá bản ghi room_time_slots (room_id, timeslot_id,
     * date=null), KHÁC với việc sửa giá (giữ nguyên bản ghi, chỉ đổi price/over_night — xem
     * update() ở trên). Chỉ áp dụng phòng styles=1. Gỡ luôn ưu đãi/coupon đang gán cho khung giờ
     * này của phòng (promotion_room_time_slot, coupon_room_time_slot) — tránh mồ côi dữ liệu vì 2
     * bảng đó không có ràng buộc FK cascade.
     */
    public function deleteTimeSlot(Request $request, string $id, int $timeslotId): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);

        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ((int) $room->styles !== 1) {
            return response()->json(['message' => 'time_slots chỉ áp dụng cho phòng theo khung giờ (styles=1).'], 422);
        }

        $roomTimeSlot = RoomTimeSlot::where('room_id', $room->id)
            ->where('timeslot_id', $timeslotId)
            ->whereNull('date')
            ->first();

        if (! $roomTimeSlot) {
            return response()->json(['message' => 'Phòng chưa gán khung giờ này.'], 404);
        }

        DB::transaction(function () use ($roomTimeSlot) {
            $roomTimeSlot->promotions()->detach();
            $roomTimeSlot->coupons()->detach();
            $roomTimeSlot->delete();
        });

        $room->load(['roomTimeSlots' => fn ($q) => $q->whereNull('date')]);

        return response()->json($this->toItem($room));
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
        $isDaily = (int) $room->styles === 2;

        $item = [
            'room' => [
                'id'     => $room->id,
                'name'   => $room->name,
                'styles' => (int) $room->styles,
                // products.price — giá gốc/đêm, chỉ có ý nghĩa với phòng theo ngày (styles=2). Phòng
                // styles=1 định giá theo TỪNG khung giờ (xem 'time_slots' bên dưới) nên không trả field
                // này để tránh nhầm giá.
                ...($isDaily ? ['price' => (float) $room->price] : []),
            ],
        ];

        // time_slots (room_time_slots) CHỈ có ý nghĩa với phòng theo khung giờ (styles=1) — phòng
        // theo ngày (styles=2) không có khái niệm "khung giờ", nên bỏ hẳn key này thay vì trả mảng
        // rỗng (tránh FE hiểu nhầm là phòng chưa cấu hình khung giờ nào).
        if (! $isDaily) {
            $item['time_slots'] = $room->roomTimeSlots->map(fn (RoomTimeSlot $rts) => [
                'room_time_slot_id' => $rts->id,
                'timeslot_id'       => $rts->timeslot_id,
                'price'             => $rts->price,
                'over_night'        => (bool) $rts->over_night,
            ])->values();
        }

        // full_booking_discount/bulk_discount_rules CHỈ có ý nghĩa với styles=1 (tính theo SỐ KHUNG
        // GIỜ — xem RoomDiscountCalculator); deposit_*/default_checkin/default_checkout CHỈ có ý
        // nghĩa với styles=2 (theo ĐÊM — xem docblock ProductController::updateBookingSettings()).
        // room_config dùng chung cả 2 style. Bỏ hẳn key không áp dụng thay vì trả null, tránh FE hiểu
        // nhầm là có tác dụng với style không tương ứng.
        $item['discount_settings'] = $isDaily
            ? [
                'room_config'         => $room->room_config,
                'deposit_1_night'     => $room->deposit_1_night,
                'deposit_multi_night' => $room->deposit_multi_night,
                'deposit_min_nights'  => $room->deposit_min_nights,
                'default_checkin'     => $room->default_checkin,
                'default_checkout'    => $room->default_checkout,
            ]
            : [
                'full_booking_discount' => $room->full_booking_discount,
                'bulk_discount_rules'   => $this->mapBulkDiscountRules($room->bulk_discount_rules),
                'room_config'           => $room->room_config,
            ];

        return $item;
    }

    // bulk_discount_rules KHÔNG có cột id riêng trong DB (chỉ là mảng JSON {slots,discount}) — gán
    // 'id' = vị trí (1-based) trong mảng TẠI THỜI ĐIỂM trả response, để FE có cái để gọi DELETE
    // .../bulk-discount-rules/{ruleId} xoá đúng 1 rule thay vì xoá sạch. LƯU Ý: id này chỉ đúng cho
    // tới lần ghi tiếp theo làm đổi thứ tự mảng (vd POST .../pricing gửi lại toàn bộ danh sách) — nên
    // luôn gọi lại GET để lấy id mới nhất trước khi xoá, đừng cache id từ lâu.
    private function mapBulkDiscountRules(?array $rules): array
    {
        if (empty($rules)) {
            return [];
        }

        return collect($rules)->values()->map(fn ($rule, $index) => [
            'id'       => $index + 1,
            'slots'    => $rule['slots'] ?? null,
            'discount' => $rule['discount'] ?? null,
        ])->all();
    }
}
