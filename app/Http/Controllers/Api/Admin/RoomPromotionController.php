<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Promotion\App\Models\Promotion;

/**
 * Gán/gỡ ưu đãi (Promotion) cho 1 khung giờ của phòng — ghi vào bảng pivot
 * `promotion_room_time_slot` (promotion_id, room_time_slot_id, min_quantity, custom_value). Bảng
 * này tồn tại sẵn (đọc để tính giá/preview ở PromotionCalculator, PromotionForm Filament) nhưng
 * trước nay CHƯA có bất kỳ nơi nào ghi vào — đây là API ghi đầu tiên.
 *
 * Dùng chung cho cả phòng theo khung giờ (styles=1) lẫn phòng theo ngày (styles=2) vì cả 2 kiểu
 * đều lưu đơn vị "1 khung giờ/1 ngày override" dưới dạng 1 row `room_time_slots` — chỉ khác
 * `time_slots.type` (time|date) liên kết tới.
 */
class RoomPromotionController extends Controller
{
    /**
     * GET /api/admin/rooms/{id}/promotions
     * Liệt kê ưu đãi đang gán cho các khung giờ của phòng này.
     */
    public function index(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $promotions = Promotion::whereHas('roomTimeSlots', fn ($q) => $q->where('room_id', $room->id))
            ->with(['roomTimeSlots' => fn ($q) => $q->where('room_id', $room->id)->with('timeSlot')])
            ->get();

        $data = $promotions->flatMap(fn (Promotion $promotion) => $promotion->roomTimeSlots->map(
            fn (RoomTimeSlot $rts) => [
                'promotion_id'      => $promotion->id,
                'promotion_name'    => $promotion->name,
                'room_time_slot_id' => $rts->id,
                'timeslot_id'       => $rts->timeslot_id,
                'start_time'        => $rts->timeSlot ? substr((string) $rts->timeSlot->start_time, 0, 5) : null,
                'end_time'          => $rts->timeSlot ? substr((string) $rts->timeSlot->end_time, 0, 5) : null,
                'date'              => $rts->date?->format('Y-m-d'),
                'min_quantity'      => $rts->pivot->min_quantity,
                'custom_value'      => $rts->pivot->custom_value !== null ? (float) $rts->pivot->custom_value : null,
            ]
        ))->values();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/admin/rooms/{id}/promotions
     * Body: promotion_id (required), room_time_slot_id (required, phải thuộc đúng phòng),
     *       min_quantity (nullable, default 1), custom_value (nullable).
     * Gán lại (đã tồn tại cặp promotion+slot) sẽ cập nhật min_quantity/custom_value thay vì lỗi.
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ($denied = self::denyPromotionManagement($request->user())) {
            return $denied;
        }

        $data = $request->validate([
            'promotion_id'      => 'required|integer|exists:promotions,id',
            'room_time_slot_id' => 'required|integer',
            'min_quantity'      => 'nullable|integer|min:1',
            'custom_value'      => 'nullable|numeric|min:0',
        ]);

        /** @var User $user */
        $user      = $request->user();
        $promotion = $this->visiblePromotion($user, $data['promotion_id']);
        if (! $promotion) {
            return response()->json(['message' => 'Không tìm thấy ưu đãi.'], 404);
        }

        if (! self::isUsable($promotion)) {
            return response()->json(['message' => 'Ưu đãi đang tắt hoặc đã hết hạn nên không gắn được.'], 422);
        }

        $rts = RoomTimeSlot::where('id', $data['room_time_slot_id'])->where('room_id', $room->id)->first();
        if (! $rts) {
            return response()->json(['message' => 'room_time_slot_id không thuộc phòng này.'], 422);
        }

        $pivotData = [
            'min_quantity' => $data['min_quantity'] ?? 1,
            'custom_value' => $data['custom_value'] ?? null,
        ];

        if ($promotion->roomTimeSlots()->where('room_time_slot_id', $rts->id)->exists()) {
            $promotion->roomTimeSlots()->updateExistingPivot($rts->id, $pivotData);
        } else {
            $promotion->roomTimeSlots()->attach($rts->id, $pivotData);
        }

        return response()->json(['message' => 'Đã gán ưu đãi cho khung giờ.'], 201);
    }

    /**
     * POST /api/admin/rooms/{id}/promotions/bulk
     * Gán / thay / gỡ NHIỀU ưu đãi cho NHIỀU khung giờ của phòng trong 1 lần (app: "áp khuyến mãi cho
     * các khung đã chọn" hoặc "cho tất cả khung").
     * Body: promotion_ids[] (bắt buộc), room_time_slot_ids[] (bỏ trống / all_slots=true = mọi khung
     *       giờ gốc của phòng), mode = attach (mặc định, thêm vào) | replace (thay hẳn ưu đãi của các khung
     *       đó bằng promotion_ids) | detach (gỡ promotion_ids khỏi các khung đó), min_quantity (mặc định 1).
     * Chỉ phòng theo khung giờ (styles=1); phòng theo ngày dùng POST .../date-pricing/bulk.
     */
    public function bulk(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ($denied = self::denyPromotionManagement($request->user())) {
            return $denied;
        }

        if ((int) ($room->styles ?? 1) !== 1) {
            return response()->json(['message' => 'Phòng theo ngày dùng POST /api/admin/rooms/{id}/date-pricing/bulk (attach_promotions).'], 422);
        }

        $data = $request->validate([
            'promotion_ids'       => 'required|array|min:1',
            'promotion_ids.*'     => 'integer',
            'room_time_slot_ids'  => 'nullable|array',
            'room_time_slot_ids.*' => 'integer',
            'all_slots'           => 'nullable|boolean',
            'mode'                => 'nullable|in:attach,replace,detach',
            'min_quantity'        => 'nullable|integer|min:1',
        ]);

        /** @var User $user */
        $user = $request->user();
        $promotionIds = array_values(array_unique(array_map('intval', $data['promotion_ids'])));
        foreach ($promotionIds as $promotionId) {
            $found = $this->visiblePromotion($user, $promotionId);
            if (! $found) {
                return response()->json(['message' => "Không tìm thấy ưu đãi #{$promotionId}."], 404);
            }

            // Gỡ thì cho cả ưu đãi đã tắt/hết hạn; gắn mới (attach/replace) chỉ nhận ưu đãi còn dùng được.
            if (($data['mode'] ?? 'attach') !== 'detach' && ! self::isUsable($found)) {
                return response()->json(['message' => "Ưu đãi #{$promotionId} đang tắt hoặc đã hết hạn nên không gắn được."], 422);
            }
        }

        $slots = RoomTimeSlot::where('room_id', $room->id)->whereNull('date')
            ->when(! ($data['all_slots'] ?? false) && ! empty($data['room_time_slot_ids']), fn ($q) => $q->whereIn('id', $data['room_time_slot_ids']))
            ->get();

        $requested = array_unique($data['room_time_slot_ids'] ?? []);
        if (! ($data['all_slots'] ?? false) && $requested && $slots->count() !== count($requested)) {
            return response()->json(['message' => 'Có room_time_slot_id không thuộc phòng này (hoặc không phải khung giờ gốc).'], 422);
        }

        if ($slots->isEmpty()) {
            return response()->json(['message' => 'Phòng chưa có khung giờ nào.'], 422);
        }

        $mode  = $data['mode'] ?? 'attach';
        $pivot = ['min_quantity' => $data['min_quantity'] ?? 1, 'custom_value' => null];

        \Illuminate\Support\Facades\DB::transaction(function () use ($slots, $promotionIds, $mode, $pivot) {
            foreach ($slots as $slot) {
                match ($mode) {
                    'detach'  => $slot->promotions()->detach($promotionIds),
                    'replace' => $slot->promotions()->sync(array_fill_keys($promotionIds, $pivot)),
                    default   => $slot->promotions()->syncWithoutDetaching(array_fill_keys($promotionIds, $pivot)),
                };
            }
        });

        return response()->json([
            'message'      => match ($mode) {
                'detach'  => 'Đã gỡ ưu đãi khỏi ' . $slots->count() . ' khung giờ.',
                'replace' => 'Đã thay ưu đãi của ' . $slots->count() . ' khung giờ.',
                default   => 'Đã gán ưu đãi cho ' . $slots->count() . ' khung giờ.',
            },
            'slots_count'  => $slots->count(),
            'promotion_ids' => $promotionIds,
        ]);
    }

    /**
     * DELETE /api/admin/rooms/{id}/promotions/{promotionId}?room_time_slot_id=
     */
    public function destroy(Request $request, string $id, string $promotionId): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ($denied = self::denyPromotionManagement($request->user())) {
            return $denied;
        }

        $data = $request->validate([
            'room_time_slot_id' => 'required|integer',
        ]);

        $rts = RoomTimeSlot::where('id', $data['room_time_slot_id'])->where('room_id', $room->id)->first();
        if (! $rts) {
            return response()->json(['message' => 'room_time_slot_id không thuộc phòng này.'], 422);
        }

        /** @var User $user */
        $user      = $request->user();
        $promotion = $this->visiblePromotion($user, (int) $promotionId);
        if (! $promotion) {
            return response()->json(['message' => 'Không tìm thấy ưu đãi.'], 404);
        }

        $promotion->roomTimeSlots()->detach($rts->id);

        return response()->json(['message' => 'Đã gỡ ưu đãi.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Gắn/gỡ khuyến mãi (theo khung giờ lẫn theo ngày) CHỈ super_admin — cùng quy ước với CMS
     *  (SettingBook bỏ qua khuyến mãi của đối tác) và DailyRoomPricingController::canEditDates().
     *  Đối tác chỉnh giá gốc + cấu hình chung qua POST /api/admin/rooms/{id}/pricing. */
    public static function denyPromotionManagement(User $user): ?JsonResponse
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        return response()->json([
            'message' => 'Chỉ super admin được gắn hoặc gỡ khuyến mãi cho khung giờ / ngày của phòng. Đối tác chỉnh giá gốc, tiền cọc, giờ nhận/trả và số khách qua POST /api/admin/rooms/{id}/pricing.',
            'error'   => 'promotion_forbidden',
        ], 403);
    }

    /** Ưu đãi đang bật và còn hạn (cùng điều kiện danh sách chọn ở CMS / DailyRoomPricingService::options). */
    public static function isUsable(Promotion $promotion): bool
    {
        return (bool) $promotion->is_active
            && ($promotion->end_at === null || $promotion->end_at->gte(now()->startOfDay()));
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

    // Promotion::BelongsToPartner chỉ tự lọc theo partner_id BÊN TRONG Filament panel (global scope
    // check AdminPanelContext::isActive()) — API route KHÔNG chạy qua panel đó nên phải lọc thủ công,
    // cùng nguyên tắc validateServiceIds()/visibleProductsQuery() ở ProductController.
    private function visiblePromotion(User $user, int $promotionId): ?Promotion
    {
        $query = Promotion::where('id', $promotionId);
        if (! $user->seesAllPartners()) {
            $query->where('partner_id', $user->partner_id);

            $allowedCategoryIds = $user->allowedCategoryIds();
            if (! empty($allowedCategoryIds)) {
                $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allowedCategoryIds));
            }
        }

        return $query->first();
    }
}
