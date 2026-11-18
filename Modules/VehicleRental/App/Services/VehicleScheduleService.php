<?php

namespace Modules\VehicleRental\App\Services;

use App\Models\Customer;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBlock;
use Modules\VehicleRental\App\Models\VehicleBooking;

/**
 * Dữ liệu lịch xe (Gantt): mỗi xe một hàng, trên đó là các đơn thuê và khoảng khoá xe trong [from, to). Dùng chung cho trang
 * CMS "Lịch xe" và API GET /api/admin/vehicle-rental/schedule.
 *
 * Hiển thị: đơn chờ thanh toán còn hạn giữ chỗ, đã xác nhận, đang thuê, đã nhận lại, đã tất toán. Không hiển thị đơn đã huỷ /
 * không đến nhận / hết hạn giữ chỗ (đã nhả xe). Đơn đang thuê mà quá hạn trả kéo dài tới hiện tại và đánh dấu overdue.
 */
class VehicleScheduleService
{
    public const MAX_DAYS = 62;

    public const SHOWN_STATUSES = [
        VehicleBooking::STATUS_PENDING_PAYMENT,
        VehicleBooking::STATUS_CONFIRMED,
        VehicleBooking::STATUS_PICKED_UP,
        VehicleBooking::STATUS_RETURNED,
        VehicleBooking::STATUS_SETTLED,
    ];

    /**
     * @return array{from:string, to:string, vehicles:array<int, array<string, mixed>>}
     */
    public function build(CarbonInterface $from, CarbonInterface $to, ?int $branchId = null, ?string $type = null): array
    {
        $vehicles = Vehicle::query()
            ->where('status', '!=', Vehicle::STATUS_INACTIVE)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('sort_order')->orderBy('id')->get();
        $ids = $vehicles->pluck('id');

        $bookings = VehicleBooking::query()->with('roomOrder:id,order_code')
            ->whereIn('vehicle_id', $ids)->whereIn('status', self::SHOWN_STATUSES)
            ->where(function ($q) {
                $q->where('status', '!=', VehicleBooking::STATUS_PENDING_PAYMENT)
                    ->orWhereNull('hold_expires_at')->orWhere('hold_expires_at', '>', now());
            })
            ->where('start_at', '<', $to)
            ->where(function ($q) use ($from) {
                $q->where('end_at', '>', $from)->orWhere('status', VehicleBooking::STATUS_PICKED_UP);
            })
            ->orderBy('start_at')->get()->groupBy('vehicle_id');

        $blocks = VehicleBlock::query()->whereIn('vehicle_id', $ids)
            ->where('start_at', '<', $to)->where('end_at', '>', $from)->orderBy('start_at')->get()->groupBy('vehicle_id');

        $customers = Customer::query()->whereIn('id', $bookings->flatten()->pluck('customer_id')->unique())->get(['id', 'fullname', 'phone'])->keyBy('id');

        $rows = [];
        foreach ($vehicles as $v) {
            $items = [];
            foreach ($bookings->get($v->id, []) as $b) {
                $overdue = $b->status === VehicleBooking::STATUS_PICKED_UP && $b->end_at->lt(now());
                $items[] = [
                    'kind'          => 'booking',
                    'id'            => $b->id,
                    'code'          => $b->code,
                    'status'        => $b->status,
                    'start_at'      => $b->start_at->toIso8601String(),
                    'end_at'        => ($overdue ? now() : $b->end_at)->toIso8601String(),
                    'planned_end_at' => $b->end_at->toIso8601String(),
                    'overdue'       => $overdue,
                    'customer_name' => $customers->get($b->customer_id)?->fullname,
                    'customer_phone' => $customers->get($b->customer_id)?->phone,
                    'room_order_code' => $b->roomOrder?->order_code,
                ];
            }
            foreach ($blocks->get($v->id, []) as $bl) {
                $items[] = [
                    'kind'     => 'block',
                    'id'       => $bl->id,
                    'reason'   => $bl->reason,
                    'start_at' => $bl->start_at->toIso8601String(),
                    'end_at'   => $bl->end_at->toIso8601String(),
                ];
            }

            $rows[] = [
                'id'       => $v->id,
                'plate'    => $v->plate_display,
                'brand'    => $v->brand,
                'model'    => $v->model,
                'type'     => $v->type,
                'status'   => $v->status,
                'branch_id' => $v->branch_id,
                'items'    => $items,
            ];
        }

        return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'vehicles' => $rows];
    }

    /** Khoảng thời gian hợp lệ cho lịch: from < to và không quá MAX_DAYS ngày. */
    public function normalizeRange(?string $from, ?string $to): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->startOfDay();
        $end = $to ? Carbon::parse($to)->startOfDay() : $start->copy()->addDays(14);

        if ($end->lte($start)) {
            throw new \DomainException('Ngày kết thúc phải sau ngày bắt đầu.');
        }
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw new \DomainException('Chỉ xem lịch tối đa ' . self::MAX_DAYS . ' ngày một lần.');
        }

        return [$start, $end];
    }
}
