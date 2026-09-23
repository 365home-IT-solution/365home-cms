<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services\Report;

use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;

// Ảnh chụp tức thời tình trạng phòng (không theo kỳ báo cáo — trạng thái phòng luôn là "hiện tại") —
// mirror Modules\Dashboard\App\Services\Report\RoomReportService::room_status bên Home, đổi 4 trạng
// thái enum của MiniHouse (Room::STATUS_*) thay vì occupied/cleaning/available của Home.
class RoomReportService
{
    private const STATUS_LABELS = [
        Room::STATUS_EMPTY    => 'Còn trống',
        Room::STATUS_RESERVED => 'Đã đặt cọc',
        Room::STATUS_RENTED   => 'Đang thuê',
        Room::STATUS_REPAIR   => 'Bảo trì',
    ];

    /**
     * @param int[] $buildingIds
     */
    public static function occupancy(array $buildingIds): array
    {
        $rooms = Room::query()
            ->whereIn('building_id', $buildingIds)
            ->with('detail')
            ->get();

        $totalRooms = $rooms->count();

        $byStatus = collect(self::STATUS_LABELS)->map(function (string $label, string $status) use ($rooms, $totalRooms) {
            $count = $rooms->filter(fn (Room $room) => $room->status === $status)->count();

            return [
                'status'  => $status,
                'label'   => $label,
                'count'   => $count,
                'percent' => $totalRooms > 0 ? round($count / $totalRooms * 100, 1) : 0,
            ];
        })->values()->all();

        $byBuilding = [];

        if (count($buildingIds) > 1) {
            $buildingNames = Building::query()->withoutGlobalScopes()->whereIn('id', $buildingIds)->pluck('name', 'id');

            $byBuilding = $rooms->groupBy('building_id')->map(function ($buildingRooms, $buildingId) use ($buildingNames) {
                $total = $buildingRooms->count();
                $rented = $buildingRooms->filter(fn (Room $room) => $room->status === Room::STATUS_RENTED)->count();

                return [
                    'building_id'    => (int) $buildingId,
                    'building_name'  => $buildingNames->get((int) $buildingId, '—'),
                    'total_rooms'    => $total,
                    'rented_rooms'   => $rented,
                    'occupancy_rate' => $total > 0 ? round($rented / $total * 100, 1) : 0,
                ];
            })->sortByDesc('occupancy_rate')->values()->all();
        }

        return [
            'total_rooms' => $totalRooms,
            'by_status'   => $byStatus,
            'by_building' => $byBuilding,
        ];
    }

    // Top phòng theo doanh thu ĐÃ THU trong kỳ (InvoicePayment đã duyệt, quy về đúng phòng qua
    // invoice.contract.room) — tương đương "top_rooms" của Modules\Dashboard\App\Services\Report\
    // RoomReportService bên Home. KHÔNG dùng invoiced_total (hoá đơn đã lập) vì tiền chưa thu không
    // phải doanh thu thật, cùng nguyên tắc "collected" đã áp dụng ở FinancialReportService.
    /**
     * @param int[] $buildingIds
     */
    public static function topRooms(array $buildingIds, Carbon $start, Carbon $end, int $limit = 10): array
    {
        $payments = InvoicePayment::query()
            ->where('status', InvoicePayment::STATUS_APPROVED)
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->whereHas('invoice', fn ($q) => $q->withoutGlobalScopes()->whereHas(
                'contract',
                fn ($q2) => $q2->withoutGlobalScopes()->whereHas(
                    'room',
                    fn ($q3) => $q3->withoutGlobalScopes()->whereIn('building_id', $buildingIds),
                ),
            ))
            ->with([
                'invoice'                       => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract'               => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract.room'          => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract.room.building' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get()
            ->groupBy(fn (InvoicePayment $p) => $p->invoice?->contract?->room_id);

        return $payments
            ->map(function ($roomPayments) {
                $room = $roomPayments->first()->invoice?->contract?->room;

                return [
                    'room_id'       => $room?->id,
                    'room_code'     => $room?->code ?? '—',
                    'building_name' => $room?->building?->name ?? '—',
                    'collected'     => (float) $roomPayments->sum('amount'),
                    'payment_count' => $roomPayments->count(),
                ];
            })
            ->sortByDesc('collected')
            ->values()
            ->take($limit)
            ->all();
    }
}
