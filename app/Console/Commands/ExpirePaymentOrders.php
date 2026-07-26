<?php

namespace App\Console\Commands;

use App\Services\SlotRealtimeService;
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

    public function handle(SlotRealtimeService $realtimeService)
    {
        $this->info('🔍 Đang kiểm tra đơn hàng hết hạn...');

        $orders = Order::with('items.product.roomTimeSlots.timeSlot')
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
                // Chụp lại khung giờ/khoảng ngày đang giữ chỗ TRƯỚC khi đổi status, để bắn realtime
                // "giải phóng" cho khách/admin khác đang xem — cùng cách suy timeslot_id từ
                // checkin_date đã dùng ở Admin\OrderController::update() (order_items không lưu
                // timeslot_id trực tiếp).
                $room = $order->items->first()?->product;

                $this->broadcastRelease($realtimeService, $room, $order->items);

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

    /**
     * Bắn realtime "giải phóng" khung giờ/ngày của đơn vừa hết hạn — áp dụng cho cả đơn của
     * khách hàng lẫn admin tạo hộ (lệnh này không phân biệt nguồn gốc đơn). Trước fix này, đơn
     * pending tự huỷ sau 15 phút chỉ đổi status trong DB, không có ai được báo qua WebSocket nên
     * lưới giờ/lịch ngày ở phía đang xem vẫn hiển thị "đã giữ" cho tới khi tự tải lại trang.
     */
    private function broadcastRelease(SlotRealtimeService $realtimeService, $room, $items): void
    {
        if (! $room || $items->isEmpty()) {
            return;
        }

        if ((int) $room->styles === 2) {
            $checkin  = $items->pluck('checkin_date')->filter()->min();
            $checkout = $items->pluck('checkout_date')->filter()->max();

            if ($checkin && $checkout) {
                $realtimeService->broadcastDailyReleased(
                    (string) $room->id,
                    Carbon::parse($checkin)->toDateString(),
                    Carbon::parse($checkout)->toDateString(),
                );
            }

            return;
        }

        $recurringSlotsByStartTime = $room->roomTimeSlots
            ->filter(fn ($rts) => $rts->timeSlot && is_null($rts->date))
            ->keyBy(fn ($rts) => $rts->timeSlot->start_time);

        $items
            ->filter(fn ($item) => $item->checkin_date)
            ->map(function ($item) use ($recurringSlotsByStartTime) {
                $checkin = Carbon::parse($item->checkin_date);
                $rts     = $recurringSlotsByStartTime->get($checkin->format('H:i:s'));

                return $rts ? ['date' => $checkin->toDateString(), 'timeslot_id' => $rts->timeslot_id] : null;
            })
            ->filter()
            ->groupBy('date')
            ->each(fn ($slots, $date) => $realtimeService->broadcastSlotsAvailable(
                (string) $room->id,
                $date,
                $slots->pluck('timeslot_id')->values()->toArray(),
            ));
    }
}