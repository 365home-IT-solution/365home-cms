<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    // Backfill ƯỚC TÍNH discount_amount cho các dòng coupon_usages đang NULL (tạo bởi migration
    // 2026_08_28_000002 — dữ liệu lịch sử, orders cũ không lưu số tiền đã giảm ở đâu cả).
    //
    // Cách tính: orders.full_amount là tổng tiền CUỐI CÙNG sau khi áp mã, CỐ ĐỊNH từ lúc đặt (xem
    // comment tại Order::create() trong ProductDetail.php — 'full_amount' => $fullAmount, với
    // $fullAmount = $orderTotal = tổng sau khi đã trừ hết coupon). Giải NGƯỢC ra tổng TRƯỚC khi áp
    // mã bằng đúng công thức cascading calculateCouponDiscounts() dùng lúc đặt (mã % áp trước trên
    // số tiền lớn hơn, mã fixed áp sau — xem ProductDetail.php), dùng luật giảm giá (type/value/
    // max_discount) HIỆN TẠI của từng coupon, rồi đi xuôi lại để tách số tiền từng mã.
    //
    // ƯỚC TÍNH — KHÔNG PHẢI SỐ THẬT LỊCH SỬ. Sẽ SAI nếu: (1) coupon đã bị sửa type/value/
    // max_discount sau khi đơn này đặt; (2) mức giảm thật lúc đó có chạm trần max_discount (giả
    // định KHÔNG chạm). Đơn áp nhiều mã cùng lúc sai số cộng dồn qua từng mã, kém tin cậy hơn đơn
    // chỉ dùng 1 mã. Đơn có mã % ~100% (không giải ngược an toàn được) bị bỏ qua, giữ nguyên NULL.
    public function up(): void
    {
        $usages = DB::table('coupon_usages')
            ->whereNull('discount_amount')
            ->orderBy('order_id')
            ->get(['id', 'order_id', 'coupon_id']);

        if ($usages->isEmpty()) {
            return;
        }

        $fullAmounts = DB::table('orders')
            ->whereIn('id', $usages->pluck('order_id')->unique())
            ->pluck('full_amount', 'id');

        $coupons = DB::table('coupons')
            ->whereIn('id', $usages->pluck('coupon_id')->unique())
            ->get(['id', 'type', 'value', 'max_discount'])
            ->keyBy('id');

        $updated = 0;
        $now     = now();

        foreach ($usages->groupBy('order_id') as $orderId => $rows) {
            $finalAmount = (float) ($fullAmounts[$orderId] ?? 0);
            if ($finalAmount <= 0) {
                continue;
            }

            // Giữ thứ tự đã lưu (= thứ tự khách áp mã, xem coupon_codes ở ProductDetail.php), rồi
            // đưa mã % lên trước - fixed xuống sau — sort ổn định (PHP 8 usort/Collection stable)
            // giống hệt calculateCouponDiscounts().
            $sorted = $rows->sortByDesc(function ($row) use ($coupons) {
                $coupon = $coupons[$row->coupon_id] ?? null;
                return $coupon && $coupon->type === 'percentage' ? 1 : 0;
            })->values();

            $percentProduct = 1.0;
            $fixedSum       = 0.0;
            $steps          = [];

            foreach ($sorted as $row) {
                $coupon = $coupons[$row->coupon_id] ?? null;
                if (! $coupon) {
                    continue; // Coupon đã bị xoá — không biết luật, bỏ qua dòng này (giữ NULL).
                }

                $max = $coupon->max_discount !== null ? (float) $coupon->max_discount : null;

                if ($coupon->type === 'percentage') {
                    $v = (float) $coupon->value;
                    $percentProduct *= (1 - $v / 100);
                    $steps[] = ['id' => $row->id, 'type' => 'percentage', 'value' => $v, 'max' => $max];
                } else {
                    $fixedSum += (float) $coupon->value;
                    $steps[] = ['id' => $row->id, 'type' => 'fixed', 'value' => (float) $coupon->value, 'max' => $max];
                }
            }

            if (empty($steps) || $percentProduct <= 0.0001) {
                continue;
            }

            // T = tổng TRƯỚC khi áp mã, giả định không mã nào chạm max_discount.
            $base      = ($finalAmount + $fixedSum) / $percentProduct;
            $remaining = $base;

            foreach ($steps as $step) {
                $discount = $step['type'] === 'percentage'
                    ? $remaining * $step['value'] / 100
                    : $step['value'];

                if ($step['max'] !== null) {
                    $discount = min($discount, $step['max']);
                }
                $discount = max(0.0, min($discount, $remaining));
                $remaining -= $discount;

                DB::table('coupon_usages')->where('id', $step['id'])->update([
                    'discount_amount' => (int) round($discount),
                    'updated_at'      => $now,
                ]);
                $updated++;
            }
        }

        Log::info("Backfill discount_amount (ước tính): đã cập nhật {$updated} dòng coupon_usages.");
    }

    public function down(): void
    {
        // Không phân biệt được dòng do migration này set với dòng ghi thật sau đó — không revert
        // về NULL để tránh xoá nhầm dữ liệu usage mới hơn (cùng lý do với migration
        // 2026_08_28_000002).
    }
};
