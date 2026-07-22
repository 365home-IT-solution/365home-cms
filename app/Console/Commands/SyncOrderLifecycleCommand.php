<?php

namespace App\Console\Commands;

use App\Services\OrderRealtimeService;
use Illuminate\Console\Command;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;

/**
 * Safety-net: tự động trả phòng (order_status = checked_out) khi khách quá giờ
 * checkout nhưng không tự mở khoá lần 2. Các transition khác (chờ→đang ở,
 * đang ở→trả phòng khi khách tự mở khoá) đã event-driven qua UnlockController.
 */
class SyncOrderLifecycleCommand extends Command
{
    protected $signature   = 'orders:sync-lifecycle';
    protected $description = 'Tự động chuyển order_status sang checked_out khi quá giờ checkout mà khách chưa tự trả phòng';

    public function handle(OrderRealtimeService $realtime): int
    {
        $overdueOrderIds = OrderItem::where('extra_fee', 0)
            ->whereNotNull('checkout_date')
            ->where('checkout_date', '<', now())
            ->whereHas('order', fn ($q) => $q->where('order_status', 'staying'))
            ->pluck('order_id')
            ->unique();

        $orders = Order::whereIn('id', $overdueOrderIds)
            ->where('order_status', 'staying')
            ->get();

        foreach ($orders as $order) {
            $order->update(['order_status' => 'checked_out', 'checked_out_at' => now()]);

            $realtime->broadcastOrderUpdate(
                (string) $order->order_code,
                ['order_status' => $order->order_status, 'payment_status' => $order->payment_status],
                $order->customer_id ? (int) $order->customer_id : null,
            );
        }

        $this->info("Đã tự động trả phòng {$orders->count()} đơn quá giờ checkout.");

        return self::SUCCESS;
    }
}
