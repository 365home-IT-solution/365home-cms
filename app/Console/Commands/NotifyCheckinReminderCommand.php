<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\NotificationFcmService;
use Illuminate\Console\Command;
use Modules\Payment\Entities\OrderItem;

/**
 * Nhắc khách check-in 2 giờ trước giờ đặt phòng.
 * Chạy mỗi 5 phút qua scheduler.
 */
class NotifyCheckinReminderCommand extends Command
{
    protected $signature   = 'notifications:checkin-reminder';
    protected $description = 'Nhắc khách check-in 2 giờ trước giờ đặt phòng';

    public function handle(NotificationFcmService $notifier): int
    {
        $now = now();

        // ── Customers: 2 giờ trước checkin ───────────────────────────────────
        $items = OrderItem::whereHas('order', fn ($q) => $q
            ->where('status', 'paid')
            ->whereNotNull('customer_id')
        )
            ->whereBetween('checkin_date', [$now->copy()->addMinutes(119), $now->copy()->addMinutes(121)])
            ->where('checkin_notified', false)
            ->with('order', 'product')
            ->get();

        foreach ($items as $item) {
            $order   = $item->order;
            $product = $item->product;
            $checkin = $item->checkin_date?->format('H:i d/m/Y') ?? '';

            if ($customer = Customer::find($order->customer_id)) {
                $notifier->sendToCustomer(
                    $customer,
                    'Nhắc nhở check-in',
                    "Phòng {$product->name} sẽ bắt đầu lúc {$checkin} (còn 2 giờ). Vui lòng đến đúng giờ!",
                    'checkin_reminder',
                    ['order_code' => (string) $order->order_code],
                );
            }

            $item->update(['checkin_notified' => true]);
        }

        // ── Guests: 5 phút trước checkin ─────────────────────────────────────
        $guestItems = OrderItem::whereHas('order', fn ($q) => $q
            ->whereIn('status', ['paid', 'deposit'])
            ->whereNull('customer_id')
            ->whereNotNull('device_token')
        )
            ->whereBetween('checkin_date', [$now->copy()->addMinutes(4), $now->copy()->addMinutes(6)])
            ->where('checkin_notified', false)
            ->with('order', 'product')
            ->get();

        foreach ($guestItems as $item) {
            $order   = $item->order;
            $product = $item->product;
            $checkin = $item->checkin_date?->format('H:i d/m/Y') ?? '';

            try {
                $notifier->sendToGuestToken(
                    $order->device_token,
                    'Nhắc nhở check-in',
                    "Phòng {$product->name} sẽ bắt đầu lúc {$checkin} (còn 5 phút). Vui lòng đến đúng giờ!",
                    'checkin_reminder',
                    ['order_code' => (string) $order->order_code],
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Guest checkin FCM failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }

            $item->update(['checkin_notified' => true]);
        }

        $total = $items->count() + $guestItems->count();
        $this->info("Đã gửi {$total} nhắc check-in ({$items->count()} customers, {$guestItems->count()} guests).");

        return self::SUCCESS;
    }
}
