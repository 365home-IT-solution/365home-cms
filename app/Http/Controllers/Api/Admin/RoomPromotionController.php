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
            ->with(['roomTimeSlots' => fn ($q) => $q->where('room_id', $room->id)])
            ->get();

        $data = $promotions->flatMap(fn (Promotion $promotion) => $promotion->roomTimeSlots->map(
            fn (RoomTimeSlot $rts) => [
                'promotion_id'      => $promotion->id,
                'promotion_name'    => $promotion->name,
                'room_time_slot_id' => $rts->id,
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
     * DELETE /api/admin/rooms/{id}/promotions/{promotionId}?room_time_slot_id=
     */
    public function destroy(Request $request, string $id, string $promotionId): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
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

    private function visibleRoom(User $user, string $id): ?Product
    {
        $room = Product::find($id);
        if (! $room || (! $user->isSuperAdmin() && $room->partner_id !== $user->partner_id)) {
            return null;
        }

        return $room;
    }

    // Promotion::BelongsToPartner chỉ tự lọc theo partner_id BÊN TRONG Filament panel (global scope
    // check AdminPanelContext::isActive()) — API route KHÔNG chạy qua panel đó nên phải lọc thủ công,
    // cùng nguyên tắc validateServiceIds()/visibleProductsQuery() ở ProductController.
    private function visiblePromotion(User $user, int $promotionId): ?Promotion
    {
        $query = Promotion::where('id', $promotionId);
        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        return $query->first();
    }
}
