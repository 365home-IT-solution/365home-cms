<?php

namespace Modules\VehicleRental\App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Models\VehicleCharge;
use Modules\VehicleRental\App\Models\VehicleHandover;

/**
 * Giao xe → nhận lại xe → tất toán tại quầy chi nhánh. Mọi lỗi nghiệp vụ ném DomainException (tiếng Việt).
 *
 * Tiền: tiền thuê đã trả online lúc đặt. Cọc thế chấp thu TẠI QUẦY lúc giao (không phải doanh thu). Phụ phí phát sinh
 * (trễ giờ, km vượt tự tính từ snapshot giá lúc đặt; thiếu xăng/vệ sinh/hư hỏng do nhân viên nhập) trừ vào cọc khi tất toán:
 * dư cọc thì hoàn lại, phụ phí vượt cọc thì khách trả thêm tại quầy.
 */
class VehicleHandoverService
{
    public function __construct(
        private VehiclePricingService $pricing,
        private VehicleKycService $kyc,
        private VehicleRentalNotifier $notifier,
        private VehicleGpsService $gps,
        private VehicleGpsAlertService $gpsAlerts,
    ) {
    }

    /**
     * Giao xe cho khách.
     *
     * @param  array{odometer_km:int, fuel_level?:?int, photos?:array, notes?:?string, deposit_collected:int}  $data
     */
    public function pickup(VehicleBooking $booking, ?string $staffId, array $data): VehicleBooking
    {
        return DB::transaction(function () use ($booking, $staffId, $data) {
            $b = $this->lock($booking);
            $vehicle = Vehicle::query()->whereKey($b->vehicle_id)->lockForUpdate()->firstOrFail();

            if ($b->status !== VehicleBooking::STATUS_CONFIRMED) {
                throw new DomainException('Chỉ giao xe cho đơn đã xác nhận (đã thanh toán).');
            }
            if (now()->lt($b->start_at->copy()->subMinutes((int) config('vehiclerental.pickup_early_minutes', 60)))) {
                throw new DomainException('Chưa đến giờ nhận xe (sớm nhất ' . (int) config('vehiclerental.pickup_early_minutes', 60) . ' phút trước giờ hẹn).');
            }
            if (now()->gt($b->end_at)) {
                throw new DomainException('Đơn đã quá giờ trả xe dự kiến, không thể giao xe. Hãy đánh dấu "không đến nhận" hoặc huỷ đơn.');
            }
            if ($vehicle->status !== Vehicle::STATUS_AVAILABLE) {
                throw new DomainException('Xe đang ở trạng thái không cho thuê.');
            }
            if ($reason = $this->kyc->ineligibleReason($b->customer_id, $vehicle, $b->end_at)) {
                throw new DomainException($reason);
            }
            // Số km nhân viên đọc trên đồng hồ là số CHÍNH XÁC: được phép khác (kể cả nhỏ hơn) số đang lưu trong hệ thống để chỉnh lại cho đúng;
            // chênh lệch được ghi vào biên bản để truy vết.
            $systemKm = (int) $vehicle->odometer_km;
            if ((int) $data['odometer_km'] !== $systemKm) {
                $adjust = 'Điều chỉnh số km xe trên hệ thống: ' . number_format($systemKm, 0, ',', '.') . ' → ' . number_format((int) $data['odometer_km'], 0, ',', '.') . ' km (theo đồng hồ lúc giao xe).';
                $data['notes'] = trim(($data['notes'] ?? '') . "
" . $adjust);
            }
            if ((int) $data['deposit_collected'] < $b->deposit_amount) {
                throw new DomainException('Cần thu đủ cọc thế chấp ' . number_format($b->deposit_amount, 0, ',', '.') . 'đ trước khi giao xe.');
            }

            VehicleHandover::create($this->gpsSnapshot($vehicle) + [
                'booking_id'  => $b->id,
                'type'        => VehicleHandover::TYPE_PICKUP,
                'odometer_km' => (int) $data['odometer_km'],
                'fuel_level'  => $data['fuel_level'] ?? null,
                'photos'      => $data['photos'] ?? [],
                'notes'       => $data['notes'] ?? null,
                'staff_id'    => $staffId,
                'handled_at'  => now(),
            ]);

            $vehicle->update(['odometer_km' => (int) $data['odometer_km']]);
            $b->update([
                'status'                   => VehicleBooking::STATUS_PICKED_UP,
                'actual_start_at'          => now(),
                'deposit_collected_amount' => (int) $data['deposit_collected'],
                'deposit_collected_at'     => now(),
            ]);

            return $b;
        });
    }

    /**
     * Nhận lại xe: ghi biên bản, tự tính trễ giờ + km vượt, thêm phụ phí do nhân viên nhập.
     *
     * @param  array{odometer_km:int, fuel_level?:?int, photos?:array, notes?:?string, charges?:array<int, array{type:string, label:string, amount:int, note?:?string, photos?:array}>}  $data
     */
    public function returnVehicle(VehicleBooking $booking, ?string $staffId, array $data): VehicleBooking
    {
        $returned = DB::transaction(function () use ($booking, $staffId, $data) {
            $b = $this->lock($booking);

            if ($b->status !== VehicleBooking::STATUS_PICKED_UP) {
                throw new DomainException('Chỉ nhận lại xe của đơn đang thuê.');
            }

            $pickup = $b->handover(VehicleHandover::TYPE_PICKUP);
            $startKm = $pickup?->odometer_km ?? 0;
            if ((int) $data['odometer_km'] < $startKm) {
                throw new DomainException("Số km trả ({$data['odometer_km']}) nhỏ hơn số km lúc giao ({$startKm}).");
            }
            $kmDriven = (int) $data['odometer_km'] - $startKm;
            $actualEnd = now();

            VehicleHandover::create($this->gpsSnapshot(Vehicle::query()->find($b->vehicle_id)) + [
                'booking_id'  => $b->id,
                'type'        => VehicleHandover::TYPE_RETURN,
                'odometer_km' => (int) $data['odometer_km'],
                'fuel_level'  => $data['fuel_level'] ?? null,
                'photos'      => $data['photos'] ?? [],
                'notes'       => $data['notes'] ?? null,
                'staff_id'    => $staffId,
                'handled_at'  => $actualEnd,
            ]);

            // Phụ phí tự động: tính lại theo ĐÚNG gói giá lúc đặt (snapshot), không theo giá hiện tại trên CMS.
            $snapshot = $b->pricing_snapshot ?? [];
            $kmMode = ($snapshot['mode'] ?? 'time') === 'km';
            $kmRefund = 0;
            if ($kmMode && ! empty($snapshot['plans'])) {
                // THEO KM: tiền chốt = max(km thực tế, km tối thiểu) × đơn giá km (giá lúc đặt); so với số đã thu lúc đặt:
                // thiếu → phụ phí (trừ vào cọc / thu thêm), dư → ghi nhận cần hoàn khách. Không có phụ phí trễ giờ / km vượt.
                $plan = $this->pricing->kmPlan($this->pricing->hydrate($snapshot['plans']));
                if ($plan) {
                    $actual = $this->pricing->kmAmount($plan, $kmDriven);
                    $prepaid = (int) $b->rental_amount;
                    if ($actual > $prepaid) {
                        $this->addCharge($b, 'km', 'Tiền km thực tế ' . number_format(max($kmDriven, (int) $plan->min_km), 0, ',', '.') . ' km (chênh so với ước tính)', $actual - $prepaid, null, [], $staffId);
                    } elseif ($actual < $prepaid) {
                        $kmRefund = $prepaid - $actual;
                    }
                }
            } elseif (! empty($snapshot['plans'])) {
                $late = $this->pricing->lateCharge($this->pricing->hydrate($snapshot['plans']), $b->start_at, $b->end_at, $actualEnd);
                if ($late > 0) {
                    $this->addCharge($b, 'late', 'Trả xe trễ giờ', $late, null, [], $staffId);
                }
                if (! empty($snapshot['quote'])) {
                    $km = $this->pricing->kmOverage($snapshot['quote'], $kmDriven);
                    if ($km['amount'] > 0) {
                        $this->addCharge($b, 'km', "Vượt {$km['extra_km']} km", $km['amount'], null, [], $staffId);
                    }
                }
            }

            foreach ($data['charges'] ?? [] as $c) {
                $this->addCharge($b, $c['type'], $c['label'], (int) $c['amount'], $c['note'] ?? null, $c['photos'] ?? [], $staffId);
            }

            Vehicle::query()->whereKey($b->vehicle_id)->update(['odometer_km' => (int) $data['odometer_km']]);
            $b->update([
                'status'        => VehicleBooking::STATUS_RETURNED,
                'actual_end_at' => $actualEnd,
                'km_driven'     => $kmDriven,
                'extra_amount'  => (int) $b->charges()->sum('amount'),
            ] + ($kmRefund > 0 ? ['rental_amount' => (int) $b->rental_amount - $kmRefund, 'refund_amount' => (int) $b->refund_amount + $kmRefund] : []));

            return $b;
        });

        $this->afterReturn($returned);

        return $returned->refresh();
    }

    /**
     * Sau khi chốt giao dịch nhận xe (best effort, không bao giờ làm hỏng việc nhận xe): đối chiếu km GPS với odometer và kiểm tra trả xe đúng chỗ.
     */
    private function afterReturn(VehicleBooking $b): void
    {
        try {
            $b->refresh();
            $vehicle = Vehicle::query()->find($b->vehicle_id);
            if (! $vehicle) {
                return;
            }
            $tracker = $this->gps->trackerFor($vehicle->id);

            $cmp = $this->gps->kmComparison($b);
            if ($cmp) {
                $b->update(['gps_km_driven' => $cmp['gps_km'], 'gps_km_discrepancy_pct' => $cmp['discrepancy_pct']]);
                if ($cmp['flagged']) {
                    $this->gpsAlerts->raise($vehicle, $b, $tracker, 'km_discrepancy', 'warning',
                        "Đơn {$b->code}: km GPS {$cmp['gps_km']} km lệch {$cmp['discrepancy_pct']}% so với odometer {$cmp['odometer_km']} km — nên kiểm tra lại số km ghi nhận.",
                        $cmp);
                }
            }

            $return = $b->handover(VehicleHandover::TYPE_RETURN);
            $limit = (int) $this->gps->cfg('far_from_branch_m', 500);
            if ($return && $return->gps_fresh && $return->gps_branch_distance_m !== null && $return->gps_branch_distance_m > $limit) {
                $this->gpsAlerts->raise($vehicle, $b, $tracker, 'return_far_from_branch', 'warning',
                    "Đơn {$b->code}: xe {$vehicle->plate_display} được ghi nhận trả nhưng GPS cho thấy cách chi nhánh khoảng {$return->gps_branch_distance_m} m.",
                    ['distance_m' => $return->gps_branch_distance_m, 'address' => $return->gps_address]);
            }
        } catch (\Throwable $e) {
            Log::warning('Thuê xe: lỗi đối chiếu GPS sau khi nhận xe', ['booking' => $b->code, 'error' => $e->getMessage()]);
        }
    }

    /** Ảnh chụp GPS cho biên bản (best effort): lỗi hoặc chưa ghép GPS → mảng rỗng, không ảnh hưởng giao/nhận xe. */
    private function gpsSnapshot(?Vehicle $vehicle): array
    {
        try {
            return $vehicle ? ($this->gps->snapshot($vehicle) ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('Thuê xe: không chụp được GPS cho biên bản', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** Thêm phụ phí thủ công (sau khi nhận xe, trước khi tất toán). */
    public function addCharge(VehicleBooking $booking, string $type, string $label, int $amount, ?string $note = null, array $photos = [], ?string $staffId = null): VehicleCharge
    {
        if (! array_key_exists($type, VehicleCharge::TYPES)) {
            throw new DomainException('Loại phụ phí không hợp lệ.');
        }
        if ($amount <= 0) {
            throw new DomainException('Số tiền phụ phí phải lớn hơn 0.');
        }
        if (in_array($booking->status, [VehicleBooking::STATUS_SETTLED, VehicleBooking::STATUS_CANCELLED, VehicleBooking::STATUS_NO_SHOW], true)) {
            throw new DomainException('Đơn đã đóng, không thể thêm phụ phí.');
        }

        $charge = VehicleCharge::create([
            'booking_id' => $booking->id, 'type' => $type, 'label' => $label, 'amount' => $amount,
            'note' => $note, 'photos' => $photos, 'created_by' => $staffId,
        ]);
        $booking->update(['extra_amount' => (int) $booking->charges()->sum('amount')]);

        return $charge;
    }

    public function removeCharge(VehicleBooking $booking, int $chargeId): void
    {
        if ($booking->status === VehicleBooking::STATUS_SETTLED) {
            throw new DomainException('Đơn đã tất toán, không thể xoá phụ phí.');
        }
        $booking->charges()->whereKey($chargeId)->firstOrFail()->delete();
        $booking->update(['extra_amount' => (int) $booking->charges()->sum('amount')]);
    }

    /**
     * Số liệu tất toán nếu chốt bây giờ (dùng để hiển thị trước khi nhân viên xác nhận).
     *
     * @return array{extra:int, deposit:int, deducted:int, refund:int, due:int}
     */
    public function settlementPreview(VehicleBooking $booking): array
    {
        $extra = (int) $booking->charges()->sum('amount');
        $deposit = (int) $booking->deposit_collected_amount;
        $deducted = min($deposit, $extra);

        return ['extra' => $extra, 'deposit' => $deposit, 'deducted' => $deducted, 'refund' => $deposit - $deducted, 'due' => $extra - $deducted];
    }

    /**
     * Tất toán: trừ phụ phí vào cọc, hoàn phần cọc dư. Phụ phí vượt cọc: nhân viên phải thu đủ tại quầy rồi xác nhận ($collectedDue).
     */
    public function settle(VehicleBooking $booking, ?string $staffId, bool $collectedDue = false): VehicleBooking
    {
        $settled = DB::transaction(function () use ($booking, $collectedDue) {
            $b = $this->lock($booking);

            if ($b->status !== VehicleBooking::STATUS_RETURNED) {
                throw new DomainException('Chỉ tất toán đơn đã nhận lại xe.');
            }

            $p = $this->settlementPreview($b);
            if ($p['due'] > 0 && ! $collectedDue) {
                throw new DomainException('Phụ phí vượt cọc ' . number_format($p['due'], 0, ',', '.') . 'đ: cần thu thêm của khách tại quầy rồi xác nhận đã thu.');
            }

            $b->update([
                'status'                  => VehicleBooking::STATUS_SETTLED,
                'settled_at'              => now(),
                'extra_amount'            => $p['extra'],
                'deposit_deducted_amount' => $p['deducted'],
                'deposit_refund_amount'   => $p['refund'],
                'extra_due_amount'        => $p['due'],
                'total_amount'            => max(0, $b->rental_amount - $b->discount_amount) + $p['extra'],
            ]);

            return $b;
        });

        $this->notifier->settled($settled);

        return $settled;
    }

    /** Khách không đến nhận xe: chỉ đánh dấu được sau giờ hẹn config('vehiclerental.no_show_after_minutes') phút. Tiền thuê không hoàn. */
    public function markNoShow(VehicleBooking $booking): VehicleBooking
    {
        $b = DB::transaction(function () use ($booking) {
            $b = $this->lock($booking);

            if ($b->status !== VehicleBooking::STATUS_CONFIRMED) {
                throw new DomainException('Chỉ đánh dấu "không đến nhận" cho đơn đã xác nhận.');
            }
            if (now()->lt($b->start_at->copy()->addMinutes((int) config('vehiclerental.no_show_after_minutes', 120)))) {
                throw new DomainException('Chưa quá giờ hẹn nhận xe ' . (int) config('vehiclerental.no_show_after_minutes', 120) . ' phút.');
            }

            $b->update(['status' => VehicleBooking::STATUS_NO_SHOW, 'cancelled_at' => now(), 'cancel_reason' => 'Khách không đến nhận xe']);

            return $b;
        });

        $this->notifier->noShow($b);

        return $b;
    }

    private function lock(VehicleBooking $booking): VehicleBooking
    {
        return VehicleBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
    }
}
