<?php

namespace App\Console\Commands;

use App\Services\FcmService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Carbon\Carbon;

/**
 * Gửi thông báo Telegram khi gần hết giờ / hết giờ đặt phòng
 *
 * Chạy mỗi 5 phút qua scheduler:
 *   $schedule->command('lock:notify-expiry')->everyFiveMinutes();
 */
class NotifyBookingExpiryCommand extends Command
{
    protected $signature   = 'lock:notify-expiry';
    protected $description = 'Gửi Telegram khi đặt phòng sắp hết giờ hoặc đã hết giờ';

    public function handle(TelegramService $telegram, FcmService $fcm): int
    {
        $now = now();

        // ── 1. Sắp hết giờ: 15 phút trước checkout ──────────────────────────
        $soonItems = OrderItem::whereHas('order', fn ($q) => $q->where('status', 'paid'))
            ->where('extra_fee', 0)
            ->whereBetween('checkout_date', [$now->copy()->addMinutes(14), $now->copy()->addMinutes(16)])
            ->where('expiry_notified', false)
            ->with('order', 'product')
            ->get();

        foreach ($soonItems as $item) {
            $this->sendExpiryWarning($telegram, $fcm, $item, 15);
            $item->update(['expiry_notified' => true]);
        }

        // ── 2. Đã hết giờ: checkout trong 5 phút trước ───────────────────────
        $expiredItems = OrderItem::whereHas('order', fn ($q) => $q->where('status', 'paid'))
            ->where('extra_fee', 0)
            ->whereBetween('checkout_date', [$now->copy()->subMinutes(5), $now->copy()->subMinute()])
            ->where('checkout_notified', false)
            ->with('order', 'product')
            ->get();

        foreach ($expiredItems as $item) {
            $this->sendExpiredNotice($telegram, $fcm, $item);
            $item->update(['checkout_notified' => true]);
        }

        $this->info("Đã xử lý: {$soonItems->count()} cảnh báo + {$expiredItems->count()} hết giờ");
        return self::SUCCESS;
    }

    // ── Sắp hết giờ ─────────────────────────────────────────────────────────

    private function sendExpiryWarning(TelegramService $telegram, FcmService $fcm, OrderItem $item, int $minutesLeft): void
    {
        $order    = $item->order;
        $product  = $item->product;
        $checkout = $item->checkout_date?->format('H:i d/m/Y') ?? '';
        $amount   = number_format($order->amount, 0, ',', '.');

        $msg = "⚠️ SẮP HẾT GIỜ - <b>{$product->name}</b>\n"
             . "Checkout lúc: <b>{$checkout}</b> ({$minutesLeft} phút nữa)\n"
             . "\n"
             . "Khách: {$order->buyer_name} | {$order->buyer_phone}\n"
             . "Mã đơn: <code>{$order->order_code}</code>\n"
             . "Tổng tiền: {$amount}đ";

        try {
            $telegram->sendMessage($msg);
            Log::info('Booking expiry warning sent', ['order_id' => $order->id, 'minutes' => $minutesLeft]);
        } catch (\Exception $e) {
            Log::error('Booking expiry warning failed', ['error' => $e->getMessage()]);
        }

        if ($order->customer_id) {
            $customer = \App\Models\Customer::find($order->customer_id);
            if ($customer) {
                try {
                    $fcm->sendToCustomer(
                        $customer,
                        'Sắp hết giờ phòng',
                        "Phòng {$product->name} sẽ hết giờ lúc {$checkout} ({$minutesLeft} phút nữa).",
                        ['order_code' => (string) $order->order_code, 'type' => 'expiry_warning']
                    );
                } catch (\Throwable $e) {
                    Log::warning('FCM expiry warning failed', ['error' => $e->getMessage()]);
                }
            }
        }
    }

    // ── Đã hết giờ ──────────────────────────────────────────────────────────

    private function sendExpiredNotice(TelegramService $telegram, FcmService $fcm, OrderItem $item): void
    {
        $order    = $item->order;
        $product  = $item->product;
        $checkout = $item->checkout_date?->format('H:i d/m/Y') ?? '';

        $msg = "🔴 HẾT GIỜ - <b>{$product->name}</b>\n"
             . "Đã hết giờ lúc: <b>{$checkout}</b>\n"
             . "\n"
             . "Khách: {$order->buyer_name} | {$order->buyer_phone}\n"
             . "Mã đơn: <code>{$order->order_code}</code>";

        try {
            $telegram->sendMessage($msg);
            Log::info('Booking expired notice sent', ['order_id' => $order->id]);
        } catch (\Exception $e) {
            Log::error('Booking expired notice failed', ['error' => $e->getMessage()]);
        }

        if ($order->customer_id) {
            $customer = \App\Models\Customer::find($order->customer_id);
            if ($customer) {
                try {
                    $fcm->sendToCustomer(
                        $customer,
                        'Đã hết giờ phòng',
                        "Phòng {$product->name} đã hết giờ lúc {$checkout}. Cảm ơn bạn đã sử dụng dịch vụ!",
                        ['order_code' => (string) $order->order_code, 'type' => 'expired']
                    );
                } catch (\Throwable $e) {
                    Log::warning('FCM expired notice failed', ['error' => $e->getMessage()]);
                }
            }
        }
    }
}
