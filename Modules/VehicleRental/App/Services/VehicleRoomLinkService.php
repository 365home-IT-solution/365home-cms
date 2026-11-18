<?php

namespace Modules\VehicleRental\App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Modules\Payment\Entities\Order;
use Modules\VehicleRental\App\Models\VehicleBooking;

/**
 * Thuê xe KÈM đơn phòng: tra đơn phòng của khách, thời gian lưu trú, điều kiện được thuê kèm và khung thuê xe gợi ý.
 *
 * Đơn phòng hợp lệ để thuê xe kèm: của chính khách, đã thanh toán (paid/deposit), chưa trả phòng, còn thời gian lưu trú.
 * Khung thuê xe phải nằm trong thời gian lưu trú, cho lệch tối đa config('vehiclerental.room_link_tolerance_hours') giờ mỗi đầu.
 * Thời gian lưu trú = từ check-in sớm nhất đến check-out muộn nhất trong các order_items của đơn.
 */
class VehicleRoomLinkService
{
    /** Trạng thái đơn phòng coi như đã thanh toán. */
    public const PAID_STATUSES = ['paid', 'deposit'];

    /** Trạng thái đơn phòng coi như đã huỷ/hỏng — thuê xe kèm đơn này cần được khách xem lại. */
    public const DEAD_STATUSES = ['cancelled', 'cancelled_payment', 'failed', 'refunded'];

    public function orderFor(string $customerId, string $orderCode): ?Order
    {
        return Order::withoutGlobalScopes()->with('items')
            ->where('order_code', $orderCode)->where('customer_id', $customerId)->first();
    }

    /** @return array{0: Carbon, 1: Carbon}|null null nếu đơn chưa có item nào có giờ nhận/trả */
    public function stayWindow(Order $order): ?array
    {
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        $start = $items->pluck('checkin_date')->filter()->min();
        $end = $items->pluck('checkout_date')->filter()->max();

        return $start && $end ? [Carbon::parse($start), Carbon::parse($end)] : null;
    }

    /** Lý do đơn phòng này không thuê xe kèm được; null = được. */
    public function ineligibleReason(Order $order): ?string
    {
        if (! in_array($order->status, self::PAID_STATUSES, true)) {
            return 'Đơn phòng chưa thanh toán nên chưa thể thuê xe kèm.';
        }
        if ($order->order_status === 'checked_out') {
            return 'Đơn phòng đã trả phòng.';
        }
        $window = $this->stayWindow($order);
        if (! $window) {
            return 'Đơn phòng chưa có thời gian lưu trú.';
        }
        $tolerance = (int) config('vehiclerental.room_link_tolerance_hours', 6);
        if ($window[1]->copy()->addHours($tolerance)->lte(now()->addMinutes((int) config('vehiclerental.min_lead_minutes', 30)))) {
            return 'Đã hết thời gian lưu trú, không còn khung giờ để thuê xe kèm.';
        }

        return null;
    }

    /**
     * Khung thuê xe gợi ý: từ lúc nhận phòng (hoặc sớm nhất có thể đặt, làm tròn lên 15 phút) đến lúc trả phòng.
     *
     * @return array{start_at: Carbon, end_at: Carbon}|null
     */
    public function suggestedWindow(Order $order): ?array
    {
        $window = $this->stayWindow($order);
        if (! $window) {
            return null;
        }

        $earliest = $this->roundUpTo15(now()->addMinutes((int) config('vehiclerental.min_lead_minutes', 30) + 1));
        $start = $window[0]->gt($earliest) ? $window[0]->copy() : $earliest;
        $end = $window[1]->copy();

        return $end->gt($start) ? ['start_at' => $start, 'end_at' => $end] : null;
    }

    /** Khung thuê xe có nằm trong thời gian lưu trú (± dung sai) không. @throws DomainException */
    public function assertWithinStay(Order $order, CarbonInterface $start, CarbonInterface $end): void
    {
        $window = $this->stayWindow($order);
        if (! $window) {
            throw new DomainException('Đơn phòng chưa có thời gian lưu trú.');
        }

        $tolerance = (int) config('vehiclerental.room_link_tolerance_hours', 6);
        if ($start->lt($window[0]->copy()->subHours($tolerance)) || $end->gt($window[1]->copy()->addHours($tolerance))) {
            throw new DomainException(
                'Thời gian thuê xe phải nằm trong thời gian lưu trú (' . $window[0]->format('H:i d/m') . ' - ' . $window[1]->format('H:i d/m')
                . ', cho phép lệch tối đa ' . $tolerance . ' giờ).'
            );
        }
    }

    /** Các đơn thuê xe đang kèm đơn phòng này (mới nhất trước). */
    public function linkedBookings(Order $order, ?string $customerId = null)
    {
        return VehicleBooking::query()->with(['vehicle.branch', 'roomOrder'])
            ->where('room_order_id', $order->id)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->orderByDesc('id')->get();
    }

    private function roundUpTo15(Carbon $time): Carbon
    {
        $t = $time->copy()->setSecond(0);
        $mod = $t->minute % 15;

        return $mod === 0 ? $t : $t->addMinutes(15 - $mod);
    }
}
