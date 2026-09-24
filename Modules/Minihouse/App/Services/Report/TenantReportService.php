<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services\Report;

use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\InvoicePayment;

// Tương đương "customer report" bên Home (xếp hạng khách chi tiêu nhiều nhất) — MiniHouse không có
// khái niệm "khách vãng lai đặt phòng nhiều lần" nên đổi thành "khách thuê trả tiền nhiều nhất
// TRONG KỲ" (theo InvoicePayment đã duyệt, KHÔNG tính hoá đơn chưa thu — đúng nguyên tắc "collected"
// dùng xuyên suốt các service báo cáo khác).
class TenantReportService
{
    /**
     * @param int[] $buildingIds
     */
    public static function topTenants(array $buildingIds, Carbon $start, Carbon $end, int $limit = 10): array
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
                'invoice'                  => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract'          => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract.tenant'   => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract.room'     => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get()
            ->groupBy(fn (InvoicePayment $p) => $p->invoice?->contract?->tenant_id);

        return $payments
            ->filter(fn ($tenantPayments, $tenantId) => $tenantId !== null)
            ->map(function ($tenantPayments) {
                $contract = $tenantPayments->first()->invoice?->contract;

                return [
                    'tenant_id'     => $contract?->tenant_id,
                    'tenant_name'   => $contract?->tenant?->fullname ?? '—',
                    'room_code'     => $contract?->room?->code ?? '—',
                    'collected'     => (float) $tenantPayments->sum('amount'),
                    'payment_count' => $tenantPayments->count(),
                ];
            })
            ->sortByDesc('collected')
            ->values()
            ->take($limit)
            ->all();
    }
}
