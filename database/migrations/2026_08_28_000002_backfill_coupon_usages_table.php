<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    // Dữ liệu lịch sử best-effort: dựng lại coupon_usages từ orders.coupon_code/coupon_codes đã có
    // sẵn, vì trước đây không có bảng log riêng. discount_amount để NULL (orders không lưu số tiền
    // đã giảm theo từng coupon nên không truy hồi được).
    public function up(): void
    {
        $couponIdsByCode = DB::table('coupons')->pluck('id', 'code');

        if ($couponIdsByCode->isEmpty()) {
            return;
        }

        $now      = now();
        $inserted = 0;

        DB::table('orders')
            ->select('id', 'customer_id', 'category_id', 'coupon_code', 'coupon_codes', 'created_at')
            ->where(function ($q) {
                $q->whereNotNull('coupon_code')->orWhereNotNull('coupon_codes');
            })
            ->orderBy('id')
            ->chunkById(500, function ($orders) use ($couponIdsByCode, $now, &$inserted) {
                $rows = [];

                foreach ($orders as $order) {
                    $codes = [];

                    if (! empty($order->coupon_codes)) {
                        $decoded = json_decode((string) $order->coupon_codes, true);
                        if (is_array($decoded)) {
                            $codes = $decoded;
                        }
                    }

                    if (empty($codes) && ! empty($order->coupon_code)) {
                        $codes = [$order->coupon_code];
                    }

                    foreach (array_unique(array_filter($codes)) as $code) {
                        $couponId = $couponIdsByCode[$code] ?? null;
                        if (! $couponId) {
                            continue;
                        }

                        $rows[] = [
                            'coupon_id'       => $couponId,
                            'customer_id'     => $order->customer_id,
                            'order_id'        => $order->id,
                            'category_id'     => $order->category_id,
                            'code'            => $code,
                            'discount_amount' => null,
                            'used_at'         => $order->created_at,
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ];
                    }
                }

                if (! empty($rows)) {
                    $inserted += DB::table('coupon_usages')->insertOrIgnore($rows);
                }
            });

        Log::info("Backfill coupon_usages: đã chèn {$inserted} dòng từ orders có sẵn.");
    }

    public function down(): void
    {
        // Không có cách phân biệt dòng do backfill tạo ra với dòng ghi thật sau khi migration này
        // chạy xong — không xoá gì ở down() để tránh mất dữ liệu usage mới hơn.
    }
};
