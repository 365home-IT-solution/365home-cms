<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PriceBoardSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Promotion\App\Models\Promotion;

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
        'full_booking_discount', 'full_day_price', 'bulk_discount_rules', 'room_config',
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

        $room->load(['roomTimeSlots' => fn ($q) => $q->whereNull('date')->with('promotions')]);

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
    public function update(Request $request, string $id, PriceBoardSyncService $priceBoards): JsonResponse
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
            // Ưu đãi gắn cho khung: [] = gỡ hết, bỏ key = giữ nguyên. Chỉ phòng styles=1.
            'time_slots.*.promotion_ids'      => 'nullable|array',
            'time_slots.*.promotion_ids.*'    => 'integer',

            'price'                           => 'nullable|numeric|min:0',

            'full_booking_discount'           => 'nullable|string|max:50',
            'full_day_price'                  => 'nullable|numeric|min:0|max:999999999',
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

        if ($hasTimeSlots && $error = RoomTimeSlotController::foreignTimeSlotError($room, array_column($data['time_slots'], 'timeslot_id'))) {
            return response()->json(['message' => $error], 422);
        }

        if ($hasTimeSlots) {
            $promotionIds = collect($data['time_slots'])->pluck('promotion_ids')->flatten()->filter()->unique()->values()->all();

            $touchesPromotions = collect($data['time_slots'])->contains(fn ($row) => array_key_exists('promotion_ids', $row));

            if ($touchesPromotions && $denied = RoomPromotionController::denyPromotionManagement($request->user())) {
                return $denied;
            }

            if ($promotionIds && (int) $room->styles !== 1) {
                return response()->json(['message' => 'Ưu đãi theo khung chỉ áp dụng cho phòng theo khung giờ (styles=1). Phòng theo ngày dùng /date-pricing.'], 422);
            }

            if ($promotionIds && count($this->visiblePromotionIds($request->user(), $promotionIds)) !== count($promotionIds)) {
                return response()->json(['message' => 'Có ưu đãi không tồn tại hoặc không thuộc phạm vi của bạn.', 'errors' => ['time_slots' => ['promotion_ids không hợp lệ.']]], 422);
            }

            // Chỉ nhận ưu đãi đang bật và còn hạn; ưu đãi ĐÃ gắn sẵn cho khung thì được giữ lại (như CMS).
            if ($promotionIds && (int) $room->styles === 1) {
                $attached = RoomTimeSlot::where('room_id', $room->id)->whereNull('date')
                    ->with('promotions:id')->get()->flatMap(fn ($s) => $s->promotions->pluck('id'))->unique()->all();
                $unusable = Promotion::whereIn('id', array_diff($promotionIds, $attached))->get()
                    ->reject(fn (Promotion $p) => RoomPromotionController::isUsable($p))->pluck('id')->all();

                if ($unusable) {
                    return response()->json(['message' => 'Ưu đãi #' . implode(', #', $unusable) . ' đang tắt hoặc đã hết hạn nên không gắn được.', 'errors' => ['time_slots' => ['promotion_ids không dùng được.']]], 422);
                }
            }
        }

        $before = $priceBoards->snapshotPricing($room);

        DB::transaction(function () use ($room, $data, $hasTimeSlots, $hasPrice, $hasDiscount) {
            if ($hasTimeSlots) {
                foreach ($data['time_slots'] as $row) {
                    $slot = RoomTimeSlot::updateOrCreate(
                        ['room_id' => $room->id, 'timeslot_id' => $row['timeslot_id'], 'date' => null],
                        [
                            'price'      => $row['price'] ?? null,
                            'over_night' => $row['over_night'] ?? false,
                            'checkin'    => $row['checkin'] ?? null,
                            'checkout'   => $row['checkout'] ?? null,
                            'status'     => 'available',
                        ]
                    );

                    if (array_key_exists('promotion_ids', $row) && (int) $room->styles === 1) {
                        $slot->promotions()->sync($row['promotion_ids'] ?? []);
                    }
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

        $priceBoards->afterManualPriceEdit($room, $before);

        return response()->json($this->toItem($room->fresh(['roomTimeSlots' => fn ($q) => $q->whereNull('date')->with('promotions')])));
    }

    /**
     * DELETE /api/admin/rooms/{id}/pricing/bulk-discount-rules
     * Xoá điều kiện giảm giá theo SỐ KHUNG GIỜ (products.bulk_discount_rules → null) — chỉ áp dụng
     * phòng styles=1. Không đụng tới full_booking_discount/room_config (xoá riêng nếu cần bằng
     * POST .../pricing với field đó = null).
     */
    public function deleteBulkDiscountRules(Request $request, string $id, PriceBoardSyncService $priceBoards): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);

        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ((int) $room->styles !== 1) {
            return response()->json(['message' => 'bulk_discount_rules chỉ áp dụng cho phòng theo khung giờ (styles=1).'], 422);
        }

        $before = $priceBoards->snapshotPricing($room);
        $room->update(['bulk_discount_rules' => null]);
        $priceBoards->afterManualPriceEdit($room, $before);

        return response()->json(['message' => 'Đã xoá giảm giá theo số khung giờ.']);
    }

    /**
     * DELETE /api/admin/rooms/{id}/pricing/bulk-discount-rules/{ruleId}
     * Xoá 1 rule trong bulk_discount_rules — ruleId LÀ 'id' trả về ở discount_settings.bulk_discount_rules
     * (xem mapBulkDiscountRules(), vị trí 1-based tại lần GET/POST gần nhất — gọi lại GET trước nếu
     * đã lâu chưa xem, tránh xoá nhầm rule khác do mảng đã bị ghi đè ở nơi khác).
     */
    public function deleteBulkDiscountRule(Request $request, string $id, int $ruleId, PriceBoardSyncService $priceBoards): JsonResponse
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

        $before = $priceBoards->snapshotPricing($room);
        $room->update(['bulk_discount_rules' => empty($rules) ? null : $rules]);
        $priceBoards->afterManualPriceEdit($room, $before);

        return response()->json(['message' => 'Đã xoá giảm giá theo số khung giờ.']);
    }

    /**
     * DELETE /api/admin/rooms/{id}/pricing/room-time-slots/{roomTimeSlotId}
     * Gỡ HẲN 1 khung giờ ra khỏi phòng — xoá bản ghi room_time_slots theo ĐÚNG khoá chính của nó
     * (cột 'id' trong bảng room_time_slots — CHÍNH LÀ 'room_time_slot_id' trả về ở
     * GET/POST .../pricing, KHÔNG PHẢI 'timeslot_id'), KHÁC với việc sửa giá (giữ nguyên bản ghi,
     * chỉ đổi price/over_night — xem update() ở trên). Chỉ áp dụng phòng styles=1. Gỡ luôn ưu
     * đãi/coupon đang gán cho khung giờ này của phòng (promotion_room_time_slot,
     * coupon_room_time_slot) — tránh mồ côi dữ liệu vì 2 bảng đó không có ràng buộc FK cascade.
     */
    public function deleteTimeSlot(Request $request, string $id, int $roomTimeSlotId, PriceBoardSyncService $priceBoards): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);

        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ((int) $room->styles !== 1) {
            return response()->json(['message' => 'time_slots chỉ áp dụng cho phòng theo khung giờ (styles=1).'], 422);
        }

        $roomTimeSlot = RoomTimeSlot::where('id', $roomTimeSlotId)
            ->where('room_id', $room->id)
            ->whereNull('date')
            ->first();

        if (! $roomTimeSlot) {
            return response()->json(['message' => 'Phòng chưa gán khung giờ này.'], 404);
        }

        $before = $priceBoards->snapshotPricing($room);

        DB::transaction(function () use ($roomTimeSlot) {
            $roomTimeSlot->promotions()->detach();
            $roomTimeSlot->coupons()->detach();
            $roomTimeSlot->delete();
        });

        // Không cập nhật bảng mặc định thì job đêm sẽ TẠO LẠI khung giờ vừa gỡ từ ảnh chụp cũ.
        $priceBoards->afterManualPriceEdit($room, $before);

        return response()->json(['message' => 'Đã xoá khung giờ, giá và ưu đãi gán cho khung giờ này.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param list<int> $ids  @return list<int> id ưu đãi mà người dùng này được phép dùng (cùng quy tắc RoomPromotionController). */
    private function visiblePromotionIds(User $user, array $ids): array
    {
        $query = Promotion::whereIn('id', $ids);

        if (! $user->seesAllPartners()) {
            $query->where('partner_id', $user->partner_id);

            $allowed = $user->allowedCategoryIds();
            if (! empty($allowed)) {
                $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allowed));
            }
        }

        return $query->pluck('id')->all();
    }

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
                'promotions'        => $rts->promotions->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'value' => $p->value !== null ? (float) $p->value : null,
                ])->values(),
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
                'full_day_price'        => $room->full_day_price !== null ? (int) $room->full_day_price : null,
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
