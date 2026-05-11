<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\BladeThemeV1\Services\AccessCode\AccessCodeService;
use Modules\Payment\Entities\Order;
use Carbon\Carbon;

class ExpirePaymentOrders extends Command
{
    protected $signature   = 'orders:expire-pending';
    protected $description = 'Tự động hủy các đơn hàng pending quá 15 phút';

    protected $accessCodeService;

    public function __construct(AccessCodeService $accessCodeService)
    {
        parent::__construct();
        $this->accessCodeService = $accessCodeService;
    }

    public function handle()
    {
        $this->info('🔍 Đang kiểm tra đơn hàng hết hạn...');

        $orders = Order::with('items')
            ->where('status', 'pending')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', Carbon::now())
            ->oldest('expired_at')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ Không có đơn hàng nào hết hạn.');
            return 0;
        }

        foreach ($orders as $order) {
            try {
                $order->update(['status' => 'failed']);
                $this->accessCodeService->releaseCode($order->id);

                Log::info('Order auto-expired by cron', [
                    'order_id'    => $order->id,
                    'order_code'  => $order->order_code,
                    'created_at'  => $order->created_at,
                    'expired_at'  => $order->expired_at,
                ]);

                $this->line("  ✅ Đã hủy đơn #{$order->order_code} ({$order->buyer_name})");

            } catch (\Exception $e) {
                Log::error('Error auto-expiring order', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
                $this->error("  ❌ Lỗi đơn #{$order->order_code}: {$e->getMessage()}");
            }
        }

        return 0;
    }
}