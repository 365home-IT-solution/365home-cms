<?php

namespace Modules\VehicleRental\App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payment\Entities\Order;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBooking;

/**
 * Vòng đời đơn thuê xe phía khách: tạo (chống đặt trùng) -> chờ thanh toán -> xác nhận -> huỷ/hoàn tiền.
 * Giao/nhận xe và tất toán nằm ở VehicleHandoverService. Mọi lỗi nghiệp vụ ném DomainException (message tiếng Việt hiển thị
 * thẳng cho khách). Thông báo (FCM) luôn gửi SAU khi giao dịch DB đã chốt để không báo nhầm khi rollback.
 */
class VehicleBookingService
{
    public function __construct(
        private VehicleAvailabilityService $availability,
        private VehiclePricingService $pricing,
        private VehicleRentalNotifier $notifier,
        private VehicleRoomLinkService $roomLink,
    ) {
    }

    /**
     * Tạo đơn chờ thanh toán và giữ xe config('vehiclerental.hold_minutes') phút. Khoá dòng xe trong transaction để
     * 2 khách không đặt trùng cùng lúc. Có $roomOrderId = thuê xe kèm đơn phòng: đơn phòng phải của chính khách, đã thanh
     * toán, chưa trả phòng và khung thuê xe phải nằm trong thời gian lưu trú (± config room_link_tolerance_hours).
     *
     * @throws DomainException
     */
    public function create(string $customerId, Vehicle $vehicle, CarbonInterface $start, CarbonInterface $end, ?int $roomOrderId = null, array $options = []): VehicleBooking
    {
        $this->assertWindow($start, $end);
        $this->assertRoomOrder($customerId, $roomOrderId, $start, $end);

        return DB::transaction(function () use ($customerId, $vehicle, $start, $end, $roomOrderId, $options) {
            $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->first();
            if (! $locked || ! $this->availability->isAvailable($locked, $start, $end)) {
                throw new DomainException('Xe không còn trống trong khoảng thời gian này. Vui lòng chọn xe hoặc thời gian khác.');
            }

            $plans = $locked->ratePlans()->get();
            // Thuê THEO KM: tiền ước tính theo số km dự kiến (chốt theo km thực tế lúc trả); mặc định theo thời gian (giờ/ngày).
            $kmMode = ($options['pricing'] ?? 'time') === 'km';
            $quote = $kmMode
                ? $this->pricing->quoteKm($plans, $start, $end, isset($options['estimated_km']) ? (int) $options['estimated_km'] : null)
                : $this->pricing->quote($plans, $start, $end);
            // Điểm giao xe = vị trí hiện tại của xe (GPS); chụp lại lúc đặt để lưu vào đơn.
            $pickup = app(VehicleGpsService::class)->pickupPoint($locked);

            return VehicleBooking::create([
                'code'             => $this->newCode(),
                'vehicle_id'       => $locked->id,
                'customer_id'      => $customerId,
                'room_order_id'    => $roomOrderId,
                'pickup_branch_id' => $locked->branch_id,
                'return_branch_id' => $locked->branch_id,
                'pickup_source'    => $pickup['source'],
                'pickup_address'   => $pickup['address'],
                'pickup_latitude'  => $pickup['latitude'],
                'pickup_longitude' => $pickup['longitude'],
                'pickup_located_at' => $pickup['located_at'] ? Carbon::parse($pickup['located_at']) : null,
                'start_at'         => $start,
                'end_at'           => $end,
                'status'           => VehicleBooking::STATUS_PENDING_PAYMENT,
                'hold_expires_at'  => now()->addMinutes((int) config('vehiclerental.hold_minutes', 15)),
                'rental_amount'    => $quote['rental_amount'],
                'discount_amount'  => 0,
                'deposit_amount'   => $locked->deposit_amount,
                'total_amount'     => $quote['rental_amount'],
                'pricing_snapshot' => [
                    'mode'  => $kmMode ? 'km' : 'time',
                    'plans' => $this->pricing->snapshot($plans),
                    'quote' => $quote,
                ],
            ]);
        });
    }

    /**
     * Ghi nhận tiền thuê đã về (webhook PayOS / xác nhận tay). Idempotent: gọi lại không đổi gì và không báo lại.
     *
     * Tiền về sau khi giữ chỗ hết hạn: nếu xe còn trống thì "sống lại" đơn; nếu đã có người đặt (hoặc xe bị khoá) thì
     * đơn bị huỷ và ghi nhận phải hoàn 100% số đã nhận.
     */
    public function markPaid(VehicleBooking $booking, int $amount): VehicleBooking
    {
        $event = null;

        $b = DB::transaction(function () use ($booking, $amount, &$event) {
            $b = VehicleBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($b->paid_at !== null) {
                return $b;
            }

            $revivable = $b->status === VehicleBooking::STATUS_PENDING_PAYMENT
                || ($b->status === VehicleBooking::STATUS_CANCELLED && $b->cancel_reason === VehicleBooking::CANCEL_HOLD_EXPIRED);
            if (! $revivable) {
                // Đơn đã huỷ/đang thuê... mà tiền vẫn về: ghi nhận để nhân viên hoàn lại, không đổi trạng thái.
                $b->update(['paid_amount' => $amount, 'paid_at' => now(), 'refund_amount' => $amount]);
                $event = 'refund';

                return $b;
            }

            $vehicle = Vehicle::query()->whereKey($b->vehicle_id)->lockForUpdate()->first();
            $free = $vehicle && $this->availability->isAvailable($vehicle, $b->start_at, $b->end_at, $b->id);

            if ($free) {
                $b->update([
                    'status'          => VehicleBooking::STATUS_CONFIRMED,
                    'paid_amount'     => $amount,
                    'paid_at'         => now(),
                    'hold_expires_at' => null,
                    'cancelled_at'    => null,
                    'cancel_reason'   => null,
                ]);
                $event = 'confirmed';
            } else {
                $b->update([
                    'status'        => VehicleBooking::STATUS_CANCELLED,
                    'paid_amount'   => $amount,
                    'paid_at'       => now(),
                    'refund_amount' => $amount,
                    'cancelled_at'  => now(),
                    'cancel_reason' => 'Thanh toán đến sau khi hết hạn giữ chỗ và xe đã được đặt. Cần hoàn tiền cho khách.',
                ]);
                $event = 'refund';
            }

            return $b;
        });

        match ($event) {
            'confirmed' => $this->notifier->bookingConfirmed($b),
            'refund'    => $this->notifier->latePaymentRefund($b),
            default     => null,
        };

        return $b;
    }

    /**
     * Nhân viên xác nhận THỦ CÔNG đã nhận đủ tiền (khách chuyển khoản/trả tại quầy mà webhook PayOS chưa về).
     * Cùng đường xử lý với webhook (markPaid): đơn chờ thanh toán → đã xác nhận; đơn vừa hết hạn giữ chỗ mà xe còn trống cũng "sống lại".
     * Chỉ nhận đúng số tiền của đơn để không ghi sai doanh thu.
     */
    public function confirmPaymentManually(VehicleBooking $booking, ?string $staffId = null, ?string $note = null): VehicleBooking
    {
        $revivable = $booking->status === VehicleBooking::STATUS_PENDING_PAYMENT
            || ($booking->status === VehicleBooking::STATUS_CANCELLED && $booking->cancel_reason === VehicleBooking::CANCEL_HOLD_EXPIRED);
        if ($booking->paid_at !== null) {
            throw new DomainException('Đơn này đã được ghi nhận thanh toán.');
        }
        if (! $revivable) {
            throw new DomainException('Chỉ xác nhận thanh toán cho đơn đang chờ thanh toán.');
        }

        $b = $this->markPaid($booking, (int) $booking->total_amount);
        if ($b->status !== VehicleBooking::STATUS_CONFIRMED) {
            throw new DomainException('Xe đã có người đặt trong khung giờ này nên không thể xác nhận đơn; số tiền đã nhận được ghi nhận để hoàn lại cho khách.');
        }

        \Illuminate\Support\Facades\Log::info('Thuê xe: xác nhận thanh toán thủ công', ['booking' => $b->code, 'amount' => $b->paid_amount, 'staff_id' => $staffId, 'note' => $note]);

        return $b;
    }

    /**
     * Phần trăm tiền thuê được hoàn nếu huỷ ở thời điểm $at, theo config('vehiclerental.cancellation_tiers').
     */
    public function refundPercent(VehicleBooking $booking, ?CarbonInterface $at = null): int
    {
        $hoursLeft = ($at ?? now())->diffInMinutes($booking->start_at, false) / 60;

        $tiers = collect(config('vehiclerental.cancellation_tiers', []))->sortByDesc('hours');
        foreach ($tiers as $tier) {
            if ($hoursLeft >= $tier['hours']) {
                return (int) $tier['percent'];
            }
        }

        return 0;
    }

    public function canCancel(VehicleBooking $booking): bool
    {
        return in_array($booking->status, [VehicleBooking::STATUS_PENDING_PAYMENT, VehicleBooking::STATUS_CONFIRMED], true);
    }

    /**
     * Huỷ đơn (khách tự huỷ hoặc nhân viên huỷ). Đơn chưa thanh toán: huỷ thẳng. Đơn đã thanh toán: tính hoàn theo mốc.
     * Nhân viên huỷ → báo khách; có khoản cần hoàn → báo nhân viên để chuyển khoản.
     *
     * @throws DomainException
     */
    public function cancel(VehicleBooking $booking, ?string $reason = null, bool $byStaff = false): VehicleBooking
    {
        $b = DB::transaction(function () use ($booking, $reason, $byStaff) {
            $b = VehicleBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! $this->canCancel($b)) {
                throw new DomainException('Đơn này không thể huỷ ở trạng thái hiện tại.');
            }

            // Nhân viên huỷ (VD xe hỏng) luôn hoàn 100%; khách tự huỷ hoàn theo mốc.
            $refund = $b->paid_at === null ? 0 : intdiv($b->paid_amount * ($byStaff ? 100 : $this->refundPercent($b)), 100);

            $b->update([
                'status'          => VehicleBooking::STATUS_CANCELLED,
                'cancelled_at'    => now(),
                'cancel_reason'   => $reason ?: ($byStaff ? 'Nhân viên huỷ đơn' : 'Khách huỷ đơn'),
                'refund_amount'   => $refund,
                'hold_expires_at' => null,
            ]);

            return $b;
        });

        $this->notifier->cancelled($b, $byStaff);

        return $b;
    }

    /**
     * Ghi nhận nhân viên đã chuyển khoản hoàn tiền thuê cho khách (refunded_at) và báo khách. Dùng chung cho CMS và API admin.
     *
     * @throws DomainException
     */
    public function markRefunded(VehicleBooking $booking): VehicleBooking
    {
        $b = DB::transaction(function () use ($booking) {
            $b = VehicleBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($b->refund_amount <= 0 || $b->refunded_at !== null) {
                throw new DomainException('Đơn không có khoản hoàn nào đang chờ.');
            }
            $b->update(['refunded_at' => now()]);

            return $b;
        });

        $this->notifier->refundDone($b);

        return $b;
    }

    /** Huỷ các đơn chưa thanh toán đã hết hạn giữ chỗ và báo khách. @return int số đơn đã huỷ */
    public function expirePending(): int
    {
        $count = 0;

        VehicleBooking::query()
            ->where('status', VehicleBooking::STATUS_PENDING_PAYMENT)
            ->whereNull('paid_at')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', now())
            ->eachById(function (VehicleBooking $b) use (&$count) {
                // Cập nhật có điều kiện: tiền vừa về (webhook) trong lúc quét thì không huỷ nhầm.
                $updated = VehicleBooking::query()->whereKey($b->id)
                    ->where('status', VehicleBooking::STATUS_PENDING_PAYMENT)->whereNull('paid_at')
                    ->update(['status' => VehicleBooking::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => VehicleBooking::CANCEL_HOLD_EXPIRED]);
                if ($updated) {
                    $count++;
                    $this->notifier->expired($b->refresh());
                }
            });

        return $count;
    }

    private function assertWindow(CarbonInterface $start, CarbonInterface $end): void
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new DomainException('Thời gian trả xe phải sau thời gian nhận xe.');
        }
        if ($start->lt(now()->addMinutes((int) config('vehiclerental.min_lead_minutes', 30)))) {
            throw new DomainException('Cần đặt xe trước giờ nhận ít nhất ' . (int) config('vehiclerental.min_lead_minutes', 30) . ' phút.');
        }
        if ($start->gt(now()->addDays((int) config('vehiclerental.max_advance_days', 180)))) {
            throw new DomainException('Chỉ nhận đặt xe trước tối đa ' . (int) config('vehiclerental.max_advance_days', 180) . ' ngày.');
        }
        if ($start->diffInMinutes($end) > (int) config('vehiclerental.max_rental_days', 30) * 1440) {
            throw new DomainException('Thời gian thuê tối đa ' . (int) config('vehiclerental.max_rental_days', 30) . ' ngày.');
        }
    }

    private function assertRoomOrder(string $customerId, ?int $roomOrderId, CarbonInterface $start, CarbonInterface $end): void
    {
        if ($roomOrderId === null) {
            return;
        }

        $order = Order::withoutGlobalScopes()->with('items')->whereKey($roomOrderId)->where('customer_id', $customerId)->first();
        if (! $order || ! in_array($order->status, VehicleRoomLinkService::PAID_STATUSES, true)) {
            throw new DomainException('Đơn phòng không hợp lệ hoặc chưa thanh toán.');
        }
        if ($order->order_status === 'checked_out') {
            throw new DomainException('Đơn phòng đã trả phòng, không thể thuê xe kèm.');
        }

        $this->roomLink->assertWithinStay($order, $start, $end);
    }

    private function newCode(): string
    {
        do {
            $code = 'XE' . now()->format('ymd') . strtoupper(Str::random(4));
        } while (VehicleBooking::query()->where('code', $code)->exists());

        return $code;
    }
}
