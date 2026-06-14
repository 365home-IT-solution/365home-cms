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
     * POST /api/coupons/validate
     *
     * Kiểm tra mã giảm giá và trả về số tiền được giảm.
     * Không tạo đơn, không tăng used_count.
     */
    public function validate(Request $request): JsonResponse
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
            ->where(fn ($q) => $q
                ->whereNull('customer_id')
                ->orWhere('customer_id', $customer?->id)
            )
            ->first();

        if (! $coupon) {
            return response()->json(['message' => 'Mã giảm giá không tồn tại hoặc đã hết hạn.'], 422);
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

        $applicable = match ($coupon->apply_type) {
            'all_rooms'     => true,
            'specific_room' => $coupon->room_id === $room->id,
            'specific_slot' => false, // Slot-specific coupons chỉ validate được khi tạo đơn
            default         => false,
        };

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
