<?php

namespace Modules\VehicleRental\App\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBlock;
use Modules\VehicleRental\App\Models\VehicleBooking;

/**
 * Xe còn trống trong [start, end) hay không. Hai chuyến liền nhau phải cách nhau tối thiểu
 * config('vehiclerental.buffer_minutes') phút (dọn xe, kiểm tra). Đơn chưa thanh toán chỉ giữ xe tới hold_expires_at.
 * Xe đang được thuê (picked_up) mà quá hạn trả vẫn được coi là đang bận cho tới HIỆN TẠI (xe chưa về); không chặn các khung
 * đặt xa trong tương lai — nếu đến lúc đó xe vẫn chưa về thì nhân viên xử lý (xem thông báo quá hạn trả và lịch xe).
 *
 * Khi TẠO đơn phải gọi trong transaction sau khi khoá dòng xe (lockForUpdate) để 2 khách không đặt trùng cùng lúc.
 */
class VehicleAvailabilityService
{
    public function bufferMinutes(): int
    {
        return (int) config('vehiclerental.buffer_minutes', 60);
    }

    public function isAvailable(Vehicle $vehicle, CarbonInterface $start, CarbonInterface $end, ?int $excludeBookingId = null): bool
    {
        if ($vehicle->status !== Vehicle::STATUS_AVAILABLE || $vehicle->trashed()) {
            return false;
        }

        return ! $this->conflictingBookings($start, $end, $excludeBookingId)->where('vehicle_id', $vehicle->id)->exists()
            && ! $this->conflictingBlocks($start, $end)->where('vehicle_id', $vehicle->id)->exists();
    }

    /**
     * Danh sách xe còn trống trong khoảng thời gian, lọc tuỳ chọn theo chi nhánh và loại xe.
     *
     * @return Collection<int, Vehicle>
     */
    public function search(CarbonInterface $start, CarbonInterface $end, ?int $branchId = null, ?string $type = null): Collection
    {
        $busy = $this->conflictingBookings($start, $end)->pluck('vehicle_id')
            ->merge($this->conflictingBlocks($start, $end)->pluck('vehicle_id'))
            ->unique()
            ->all();

        return Vehicle::query()
            ->where('status', Vehicle::STATUS_AVAILABLE)
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when($type, fn (Builder $q) => $q->where('type', $type))
            ->when($busy, fn (Builder $q) => $q->whereNotIn('id', $busy))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** Các đơn đang giữ xe và chồng lấn (kể cả khoảng đệm) với [start, end). */
    public function conflictingBookings(CarbonInterface $start, CarbonInterface $end, ?int $excludeBookingId = null): Builder
    {
        $buffer = $this->bufferMinutes();
        $now = now();

        return VehicleBooking::query()
            ->whereIn('status', VehicleBooking::BLOCKING_STATUSES)
            ->when($excludeBookingId, fn (Builder $q) => $q->where('id', '!=', $excludeBookingId))
            ->where(function (Builder $q) use ($now) {
                $q->where('status', '!=', VehicleBooking::STATUS_PENDING_PAYMENT)
                    ->orWhereNull('hold_expires_at')
                    ->orWhere('hold_expires_at', '>', $now);
            })
            ->where('start_at', '<', $end->copy()->addMinutes($buffer))
            ->where(function (Builder $q) use ($start, $buffer, $now) {
                $q->where('end_at', '>', $start->copy()->subMinutes($buffer));

                // Xe đang được thuê mà quá hạn trả vẫn bận cho tới HIỆN TẠI (chưa nhận lại): nó chặn khung bắt đầu sớm hơn
                // now + đệm, không chặn các khung ở xa trong tương lai.
                if ($now->gt($start->copy()->subMinutes($buffer))) {
                    $q->orWhere('status', VehicleBooking::STATUS_PICKED_UP);
                }
            });
    }

    public function conflictingBlocks(CarbonInterface $start, CarbonInterface $end): Builder
    {
        return VehicleBlock::query()
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start);
    }
}
