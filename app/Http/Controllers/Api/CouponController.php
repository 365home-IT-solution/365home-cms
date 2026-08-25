<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Product\App\Models\Product;
use Modules\Promotion\App\Models\Coupon;

class CouponController extends Controller
{
    /**
     * GET /api/coupons/mine
     *
     * Danh sách mã giảm giá mà customer sở hữu:
     * - Coupon cá nhân từ membership tier (coupon.customer_id = customer.id)
     * - Coupon được gán qua bảng coupon_customers (many-to-many)
     * Lọc: còn hiệu lực, chưa hết lượt dùng.
     *
     * Query param room_id (tuỳ chọn): nếu gửi kèm (khách đang xem/chọn 1 phòng cụ thể), CHỈ trả về
     * mã áp dụng ĐƯỢC cho phòng đó — dùng cùng Coupon::appliesToRoom() với POST /api/coupons/check
     * (đã gồm cả kiểm tra chi nhánh, xem Coupon::passesBranchRestriction()) nên mã bị giới hạn
     * chi nhánh (gán ở "Coupon tự động cấp" của hạng thành viên, hoặc trực tiếp ở
     * /api/admin/coupons) sẽ tự động bị ẩn nếu phòng không thuộc chi nhánh được cấu hình. 'specific_slot'
     * luôn bị loại khỏi kết quả khi có room_id — giống hệt quy ước ở check() — vì cần đúng khung giờ
     * mới xác định được, không chỉ phòng; khách vẫn thấy mã này khi KHÔNG gửi room_id (xem toàn bộ
     * mã đang có, ở trang "Ví voucher" chẳng hạn).
     * Không gửi room_id = trả về TOÀN BỘ mã đang sở hữu như cũ (không lọc theo phòng/chi nhánh).
     */
    public function mine(Request $request): JsonResponse
    {
        $request->validate([
            'room_id' => 'sometimes|nullable|string|exists:products,id',
        ]);

        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $now = now();

        // Coupon cá nhân (membership tier phát, customer_id trực tiếp)
        $personal = Coupon::where('customer_id', $customer->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now))
            ->whereRaw('(usage_limit IS NULL OR used_count < usage_limit)')
            ->get();

        // Coupon được gán qua coupon_customers (many-to-many)
        $assigned = $customer->coupons()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now))
            ->whereRaw('(usage_limit IS NULL OR used_count < usage_limit)')
            ->get();

        // Gộp, loại trùng theo code — đánh dấu nguồn để biết is_personal
        $personalCodes = $personal->pluck('code')->flip(); // set for O(1) lookup

        $coupons = $personal->concat($assigned)
            ->unique('code')
            ->sortBy('end_at')
            ->values();

        $roomId = $request->filled('room_id') ? (string) $request->input('room_id') : null;
        if ($roomId !== null) {
            $coupons = $coupons
                ->filter(fn (Coupon $c) => $c->apply_type !== 'specific_slot' && $c->appliesToRoom($roomId))
                ->values();
        }

        $data = $coupons->map(fn ($c) => [
            'code'            => $c->code,
            'name'            => $c->name,
            'description'     => $c->description,
            'type'            => $c->type,
            'value'           => $c->value,
            'apply_type'      => $c->apply_type,
            'min_order_value' => $c->min_order_value,
            'max_discount'    => $c->max_discount,
            'end_at'          => $c->end_at?->toDateTimeString(),
            'is_personal'     => isset($personalCodes[$c->code]) || $c->isPersonal(),
            'usage_remaining' => $c->usage_limit !== null
                ? max(0, (int) $c->usage_limit - (int) $c->used_count)
                : null,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/coupons/check
     *
     * Kiểm tra mã giảm giá và trả về số tiền được giảm.
     * Không tạo đơn, không tăng used_count.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'coupon_code' => 'required|string',
            'room_id'     => 'required|string',
            'amount'      => 'required|integer|min:0',
        ]);

        $room = Product::where('id', $request->input('room_id'))
            ->where('is_activated', true)
            ->first();

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại hoặc đã ngừng hoạt động.'], 404);
        }

        $customer = auth('sanctum')->user();

        $coupon = Coupon::where('code', strtoupper($request->input('coupon_code')))
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->first();

        if (! $coupon) {
            return response()->json(['message' => 'Mã giảm giá không tồn tại hoặc đã hết hạn.'], 422);
        }

        // Coupon cá nhân (customer_id trực tiếp hoặc gán qua coupon_customers pivot)
        // chỉ dùng được bởi đúng khách được gán.
        $isRestricted = $coupon->customer_id !== null || $coupon->customers()->exists();
        if ($isRestricted) {
            $owns = $coupon->customer_id === $customer?->id
                || ($customer && $coupon->customers()->where('customer_id', $customer->id)->exists());

            if (! $owns) {
                return response()->json(['message' => 'Mã giảm giá không tồn tại hoặc đã hết hạn.'], 422);
            }
        }

        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            return response()->json(['message' => 'Mã giảm giá đã hết lượt sử dụng.'], 422);
        }

        $amount = (float) $request->input('amount');

        if ($coupon->min_order_value && $amount < (float) $coupon->min_order_value) {
            return response()->json([
                'message' => 'Đơn hàng chưa đạt giá trị tối thiểu ' . number_format((float) $coupon->min_order_value) . 'đ để áp dụng mã này.',
            ], 422);
        }

        // 'specific_slot' luôn false ở đây — chỉ validate được khi tạo đơn (có đúng RoomTimeSlot).
        $applicable = $coupon->apply_type !== 'specific_slot' && $coupon->appliesToRoom($room->id);

        if (! $applicable) {
            return response()->json(['message' => 'Mã giảm giá không áp dụng cho phòng này.'], 422);
        }

        $discountAmount = (int) $coupon->calculateDiscount($amount);
        $finalAmount    = max(0, (int) $amount - $discountAmount);

        return response()->json([
            'coupon' => [
                'code'            => $coupon->code,
                'name'            => $coupon->name,
                'type'            => $coupon->type,
                'value'           => $coupon->value,
                'discount_amount' => $discountAmount,
                'final_amount'    => $finalAmount,
            ],
        ]);
    }
}
