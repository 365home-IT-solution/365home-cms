<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services\Report;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Transaction;

// PORT lại logic Modules\Minihouse\App\Filament\Pages\FinanceReports sang dạng service dùng cho API
// — khác bản Filament ở đúng 1 điểm: lọc theo TẬP building_id (mảng, vì API không có khái niệm "1
// toà đang chọn ở header panel" như Filament) thay vì 1 buildingId duy nhất. $buildingIds LUÔN là
// mảng cụ thể do controller truyền vào (ScopesToMinihouseBuilding::permittedBuildingIds(), có thể
// giao thêm với ?building_id= trên query string) — KHÔNG có ý nghĩa "null = tất cả" ở tầng service
// này, việc "super_admin thấy tất cả" đã được giải quyết ở tầng permittedBuildingIds() (trả về toàn
// bộ id building cho super_admin).
class FinancialReportService
{
    /**
     * @param int[] $buildingIds
     */
    public static function stats(array $buildingIds, Carbon $start, Carbon $end): array
    {
        $collected = self::collectedInPeriod($buildingIds, $start, $end);

        $invoicedQuery = self::scopeInvoiceToBuilding(
            Invoice::query()->whereBetween('month', [$start->copy()->startOfMonth(), $end->copy()->startOfMonth()]),
            $buildingIds,
        );

        $invoicedTotal = (float) (clone $invoicedQuery)->sum('total_amount');
        $uncollected   = (float) (clone $invoicedQuery)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->sum(DB::raw('total_amount - amount_paid'));

        $expense = (float) Transaction::query()
            ->where('type', Transaction::TYPE_OUT)
            ->whereBetween('transaction_date', [$start, $end])
            ->whereIn('building_id', $buildingIds)
            ->sum('amount');

        $roomsQuery = Room::query()->whereIn('building_id', $buildingIds);
        $totalRooms = (clone $roomsQuery)->count();
        $rentedRooms = (clone $roomsQuery)->whereHas('detail', fn ($q) => $q->where('status', Room::STATUS_RENTED))->count();

        return [
            'collected'      => $collected,
            'invoiced_total' => $invoicedTotal,
            'uncollected'    => $uncollected,
            'expense'        => $expense,
            'profit'         => $collected - $expense,
            'total_rooms'    => $totalRooms,
            'rented_rooms'   => $rentedRooms,
            'occupancy_rate' => $totalRooms > 0 ? round($rentedRooms / $totalRooms * 100, 1) : 0,
        ];
    }

    /**
     * @param int[] $buildingIds
     */
    public static function byBuilding(array $buildingIds, Carbon $start, Carbon $end): array
    {
        // Chỉ 1 toà trong phạm vi thì bảng phân theo toà không còn ý nghĩa (giống early-return của
        // bản Filament khi đang lọc đúng 1 toà ở dropdown trang).
        if (count($buildingIds) <= 1) {
            return [];
        }

        $collectedByBuilding = self::scopeInvoicePaymentToBuilding(
            InvoicePayment::query()
                ->where('status', InvoicePayment::STATUS_APPROVED)
                ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()]),
            $buildingIds,
        )
            ->with([
                'invoice'                        => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract'                => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract.room'           => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract.room.building'  => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get()
            ->groupBy(fn (InvoicePayment $p) => $p->invoice?->contract?->room?->building_id);

        $uncollectedByBuilding = self::scopeInvoiceToBuilding(
            Invoice::query()
                ->whereBetween('month', [$start->copy()->startOfMonth(), $end->copy()->startOfMonth()])
                ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL]),
            $buildingIds,
        )
            ->with([
                'contract'                => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room'           => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room.building'  => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get()
            ->groupBy(fn (Invoice $invoice) => $invoice->contract?->room?->building_id);

        $buildingNames = \Modules\Minihouse\App\Models\Building::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $buildingIds)
            ->pluck('name', 'id');

        return collect($buildingIds)
            ->map(function (int $buildingId) use ($collectedByBuilding, $uncollectedByBuilding, $buildingNames) {
                $payments = $collectedByBuilding->get($buildingId, collect());
                $invoices = $uncollectedByBuilding->get($buildingId, collect());

                return [
                    'building_id'   => $buildingId,
                    'building_name' => $buildingNames->get($buildingId, '—'),
                    'count'         => $payments->count(),
                    'collected'     => (float) $payments->sum('amount'),
                    'uncollected'   => (float) $invoices->sum(fn (Invoice $invoice) => $invoice->remainingAmount()),
                ];
            })
            ->sortByDesc('collected')
            ->values()
            ->all();
    }

    // Công nợ HIỆN TẠI (không giới hạn theo kỳ báo cáo — nợ cũ vẫn là nợ), gộp theo hợp đồng/khách.
    /**
     * @param int[] $buildingIds
     */
    public static function tenantDebts(array $buildingIds): array
    {
        return self::scopeInvoiceToBuilding(
            Invoice::query()->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL]),
            $buildingIds,
        )
            ->with([
                'contract'          => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room'     => fn ($q) => $q->withoutGlobalScopes(),
                'contract.tenant'   => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get()
            ->groupBy('contract_id')
            ->map(function ($invoices) {
                $contract = $invoices->first()->contract;

                return [
                    'contract_id'   => $contract?->id,
                    'tenant'        => $contract?->tenant?->fullname ?? '—',
                    'room'          => $contract?->room?->code ?? '—',
                    'invoice_count' => $invoices->count(),
                    'total_debt'    => (float) $invoices->sum(fn (Invoice $invoice) => $invoice->remainingAmount()),
                    'oldest_month'  => optional($invoices->min('month'))?->toDateString(),
                ];
            })
            ->sortByDesc('total_debt')
            ->values()
            ->all();
    }

    /**
     * @param int[] $buildingIds
     */
    private static function collectedInPeriod(array $buildingIds, Carbon $start, Carbon $end): float
    {
        $query = InvoicePayment::query()
            ->where('status', InvoicePayment::STATUS_APPROVED)
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()]);

        return (float) self::scopeInvoicePaymentToBuilding($query, $buildingIds)->sum('amount');
    }

    /**
     * @param int[] $buildingIds
     */
    private static function scopeInvoicePaymentToBuilding($query, array $buildingIds)
    {
        return $query->whereHas('invoice', fn ($q) => $q->withoutGlobalScopes()->whereHas(
            'contract',
            fn ($q2) => $q2->withoutGlobalScopes()->whereHas(
                'room',
                fn ($q3) => $q3->withoutGlobalScopes()->whereIn('building_id', $buildingIds),
            ),
        ));
    }

    /**
     * @param int[] $buildingIds
     */
    private static function scopeInvoiceToBuilding($query, array $buildingIds)
    {
        return $query->whereHas(
            'contract',
            fn ($q2) => $q2->withoutGlobalScopes()->whereHas(
                'room',
                fn ($q3) => $q3->withoutGlobalScopes()->whereIn('building_id', $buildingIds),
            ),
        );
    }
}
