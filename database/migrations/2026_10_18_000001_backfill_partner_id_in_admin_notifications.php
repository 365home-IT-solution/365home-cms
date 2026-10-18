<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Thông báo admin cũ (bảng notifications) chưa có viewData.partner_id nên không lọc được theo
// X-Partner-Id. Bổ sung partner_id cho những thông báo gắn với đơn (viewData.order_code) bằng cách
// tra partner của đơn tương ứng. Thông báo không gắn đơn giữ nguyên (cấp nền tảng).
return new class extends Migration
{
    public function up(): void
    {
        $partnerByOrderCode = DB::table('orders')
            ->whereNotNull('partner_id')
            ->pluck('partner_id', 'order_code');

        DB::table('notifications')
            ->where('data', 'like', '%order_code%')
            ->where('data', 'not like', '%"partner_id"%')
            ->chunkById(500, function ($rows) use ($partnerByOrderCode) {
                foreach ($rows as $row) {
                    $data      = json_decode($row->data, true);
                    $orderCode = $data['viewData']['order_code'] ?? null;
                    $partnerId = $orderCode ? ($partnerByOrderCode[$orderCode] ?? null) : null;

                    if (! is_array($data) || $partnerId === null) {
                        continue;
                    }

                    $data['viewData']['partner_id'] = $partnerId;

                    DB::table('notifications')->where('id', $row->id)->update([
                        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Dữ liệu bổ sung, không cần hoàn tác.
    }
};
