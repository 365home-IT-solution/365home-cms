<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\NotificationFcmService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\OrderItem;

/**
 * Gửi thông báo Telegram (admin) + FCM (customer) khi gần hết / hết giờ đặt phòng.
 * Chạy mỗi 5 phút qua scheduler.
 */
class NotifyBookingExpiryCommand extends Command
{
    protected $signature   = 'lock:notify-expiry';
    protected $description = 'Gửi Telegram khi đặt phòng sắp hết giờ hoặc đã hết giờ';

    public function handle(TelegramService $telegram, NotificationFcmService $notifier): int
    {
        $now = now();

        // ── 1. Sắp hết giờ (customers): 15 phút trước checkout ───────────────
        $soonItems = OrderItem::whereHas('order', fn ($q) => $q
            ->where('status', 'paid')
            ->whereNotNull('customer_id')
        )
            ->where('extra_fee', 0)
            ->whereBetween('checkout_date', [$now->copy()->addMinutes(14), $now->copy()->addMinutes(16)])
            ->where('expiry_notified', false)
            ->with('order', 'product')
            ->get();

        foreach ($soonItems as $item) {
            $this->sendExpiryWarning($telegram, $notifier, $item, 15);
            $item->update(['expiry_notified' => true]);
        }

        // ── 2. Đã hết giờ (customers): checkout trong 5 phút trước ───────────
        $expiredItems = OrderItem::whereHas('order', fn ($q) => $q
            ->where('status', 'paid')
            ->whereNotNull('customer_id')
        )
            ->where('extra_fee', 0)
            ->whereBetween('checkout_date', [$now->copy()->subMinutes(5), $now->copy()->subMinute()])
            ->where('checkout_notified', false)
            ->with('order', 'product')
            ->get();

        foreach ($expiredItems as $item) {
            $this->sendExpiredNotice($telegram, $notifier, $item);
            $item->update(['checkout_notified' => true]);
        }

        // ── 3. Sắp hết giờ (guests): 5 phút trước checkout ──────────────────
        $guestSoonItems = OrderItem::whereHas('order', fn ($q) => $q
            ->whereIn('status', ['paid', 'deposit'])
            ->whereNull('customer_id')
            ->whereNotNull('device_token')
        )
            ->where('extra_fee', 0)
            ->whereBetween('checkout_date', [$now->copy()->addMinutes(4), $now->copy()->addMinutes(6)])
            ->where('expiry_notified', false)
            ->with('order', 'product')
            ->get();

        foreach ($guestSoonItems as $item) {
            $order    = $item->order;
            $product  = $item->product;
            $checkout = $item->checkout_date?->format('H:i d/m/Y') ?? '';

            try {
                $notifier->sendToGuestToken(
                    $order->device_token,
                    'Sắp hết giờ phòng',
                    "Phòng {$product->name} sẽ hết giờ lúc {$checkout} (còn 5 phút).",
                    'checkout_warning',
                    ['order_code' => (string) $order->order_code],
                );
            } catch (\Throwable $e) {
                Log::warning('Guest checkout FCM failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }

            $item->update(['expiry_notified' => true]);
        }

        $this->info(
            "Đã xử lý: {$soonItems->count()} cảnh báo + {$expiredItems->count()} hết giờ + {$guestSoonItems->count()} guest checkout"
        );

        return self::SUCCESS;
    }

    // ── Sắp hết giờ ─────────────────────────────────────────────────────────

    private function sendExpiryWarning(TelegramService $telegram, NotificationFcmService $notifier, OrderItem $item, int $minutesLeft): void
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

        if ($order->customer_id && $customer = Customer::find($order->customer_id)) {
            $notifier->sendToCustomer(
                $customer,
                'Sắp hết giờ phòng',
                "Phòng {$product->name} sẽ hết giờ lúc {$checkout} ({$minutesLeft} phút nữa).",
                'checkout_warning',
                ['order_code' => (string) $order->order_code],
            );
        }
    }

    // ── Đã hết giờ ──────────────────────────────────────────────────────────

    private function sendExpiredNotice(TelegramService $telegram, NotificationFcmService $notifier, OrderItem $item): void
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

        if ($order->customer_id && $customer = Customer::find($order->customer_id)) {
            $notifier->sendToCustomer(
                $customer,
                'Đã hết giờ phòng',
                "Phòng {$product->name} đã hết giờ lúc {$checkout}. Cảm ơn bạn đã sử dụng dịch vụ!",
                'checkout_warning',
                ['order_code' => (string) $order->order_code],
            );
        }
    }
}
