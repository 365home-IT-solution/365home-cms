<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Payment\Entities\OrderItem;

/**
 * Khi 1 đơn đặt phòng (đặt theo giờ hoặc theo ngày đều dùng chung checkout_date) hết giờ, tự
 * động chuyển phòng sang trạng thái 'cleaning' (đang dọn vệ sinh) — phòng chỉ quay lại
 * 'available' khi có nhân viên xác nhận đã dọn xong (xem ProductAction::confirmCleaning()).
 * Chạy mỗi 5 phút qua scheduler (app/Console/Kernel.php).
 */
class MarkRoomsForCleaningCommand extends Command
{
    protected $signature = 'housekeeping:mark-cleaning';
    protected $description = 'Chuyển phòng đã hết giờ đặt sang trạng thái đang dọn vệ sinh';

    public function handle(): int
    {
        $now = now();

        $expiredItems = OrderItem::query()
            ->where('housekeeping_triggered', false)
            ->whereNotNull('checkout_date')
            ->where('checkout_date', '<=', $now)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'deposit']))
            ->with('product')
            ->get();

        $markedCount = 0;

        foreach ($expiredItems as $item) {
            $product = $item->product;

            // Cùng 1 đơn, cùng 1 phòng, còn 1 khung giờ KHÁC checkout TRỄ HƠN khung giờ này —
            // nghĩa là khách vẫn tiếp tục lưu trú liên tiếp ở đúng phòng này (vd đặt 15:10-18:00
            // rồi nối tiếp 18:30-21:20), CHƯA thực sự rời phòng. Bỏ qua, không đánh dấu cần dọn ở
            // lượt này — nhường lại cho đúng lượt CUỐI CÙNG (checkout trễ nhất) của cùng đơn này
            // tự kích hoạt khi tới lượt nó (dòng đó có housekeeping_triggered riêng, độc lập, vẫn
            // sẽ được xét đúng lúc checkout thật sự tới). Chỉ áp dụng cho ĐÚNG 1 đơn — nếu khách
            // KHÁC/đơn KHÁC đặt nối tiếp ngay sau, phòng vẫn cần dọn bình thường giữa 2 lượt khách.
            $hasLaterItemSameStay = OrderItem::query()
                ->where('order_id', $item->order_id)
                ->where('product_id', $item->product_id)
                ->where('id', '!=', $item->id)
                ->where('checkout_date', '>', $item->checkout_date)
                ->exists();

            if (! $hasLaterItemSameStay && $product && $product->housekeeping_status === 'available') {
                $product->markForCleaning($item->id);
                $markedCount++;
            }

            $item->update(['housekeeping_triggered' => true]);
        }

        $this->info("Đã chuyển {$markedCount}/{$expiredItems->count()} phòng sang trạng thái đang dọn vệ sinh.");

        return self::SUCCESS;
    }
}
