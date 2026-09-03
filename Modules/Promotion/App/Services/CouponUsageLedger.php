<?php

declare(strict_types=1);

namespace Modules\Promotion\App\Services;

use Modules\Payment\Entities\Order;
use Modules\Promotion\App\Models\Coupon;
use Modules\Promotion\App\Models\CouponUsage;
use Modules\Promotion\App\Models\CouponUsageLog;

// Điểm ghi/hoàn DUY NHẤT cho việc "dùng mã giảm giá" — gọi từ OrderObserver đúng lúc đơn thanh toán
// thành công lần đầu (confirm) hoặc lúc 1 đơn đã thanh toán bị hủy/hoàn tiền (release), thay cho
// việc trừ used_count ngay lúc tạo đơn như trước đây (xem OrderController::releaseCouponUsage() cũ,
// đã xóa — logic chuyển hết vào đây để không rải rác theo từng nơi tạo/sửa đơn).
class CouponUsageLedger
{
    /**
     * Ghi nhận CHÍNH THỨC các mã đang áp trên đơn: tăng used_count + ghi coupon_usages (nghiệp vụ
     * nội bộ) + coupon_usage_logs (nhật ký xuất Excel). Bỏ qua mã đã được ghi nhận cho ĐÚNG đơn này
     * rồi (idempotent — an toàn khi gọi lại, vd đơn đổi coupon sau khi đã confirm).
     */
    public function confirm(Order $order): void
    {
        $codes = $this->orderCodes($order);
        if (empty($codes)) {
            return;
        }

        $alreadyConfirmed = CouponUsage::where('order_id', $order->id)->pluck('code')->all();
        $discountAmounts  = is_array($order->coupon_discount_amounts) ? $order->coupon_discount_amounts : [];

        foreach ($codes as $code) {
            if (in_array($code, $alreadyConfirmed, true)) {
                continue;
            }

            $coupon = Coupon::where('code', $code)->first();
            if (! $coupon) {
                continue;
            }

            $discountAmount = $discountAmounts[$code] ?? null;

            $coupon->incrementUsage(
                (string) $order->id,
                $order->customer_id,
                $order->category_id,
                $discountAmount !== null ? (int) $discountAmount : null,
            );

            CouponUsageLog::create([
                'partner_id'      => $order->partner_id,
                'coupon_id'       => $coupon->id,
                'code'            => $code,
                'coupon_name'     => $coupon->name,
                'order_id'        => $order->id,
                'order_code'      => $order->order_code,
                'customer_id'     => $order->customer_id,
                'customer_name'   => $order->customer?->fullname ?? $order->buyer_name,
                'customer_phone'  => $order->customer?->phone ?? $order->buyer_phone,
                'discount_amount' => $discountAmount,
                'order_amount'    => $order->full_amount ?? $order->amount,
                'payment_method'  => $order->payment_method,
                'category_id'     => $order->category_id,
                'used_at'         => now(),
            ]);
        }
    }

    /**
     * Hoàn lại các mã ĐÃ được confirm() cho đơn này: giảm used_count, xóa dòng coupon_usages (bảng
     * nghiệp vụ — "hiện tại đơn này không còn dùng mã nào"), và đánh dấu reversed_at trên
     * coupon_usage_logs (KHÔNG xóa — giữ nguyên làm lịch sử/audit đã xuất Excel trước đó).
     */
    public function release(Order $order): void
    {
        $usages = CouponUsage::where('order_id', $order->id)->get();

        foreach ($usages as $usage) {
            Coupon::where('id', $usage->coupon_id)->where('used_count', '>', 0)->decrement('used_count');

            CouponUsageLog::where('order_id', $order->id)
                ->where('code', $usage->code)
                ->whereNull('reversed_at')
                ->update(['reversed_at' => now()]);

            $usage->delete();
        }
    }

    private function orderCodes(Order $order): array
    {
        if (is_array($order->coupon_codes) && ! empty($order->coupon_codes)) {
            return $order->coupon_codes;
        }

        return $order->coupon_code ? [$order->coupon_code] : [];
    }
}
