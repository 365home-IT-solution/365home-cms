<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services\Report;

use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Transaction;
use Modules\Minihouse\App\Services\InvoiceContentRenderer;

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
            // Gộp theo GỐC chuỗi chuyển phòng (InvoiceContentRenderer::contractIdChain(), cùng công
            // thức đang dùng để tính previous_debt/total_owed trên hoá đơn/QR) — trước đây gộp thẳng
            // theo contract_id nên 1 khách đã chuyển phòng bị tách thành 2 DÒNG CÔNG NỢ RIÊNG (hợp
            // đồng cũ + mới), lệch hẳn với số hiện trên hoá đơn.
            ->groupBy(fn (Invoice $invoice) => (string) collect(InvoiceContentRenderer::contractIdChain($invoice))->last())
            ->map(function ($invoices) {
                // Hợp đồng MỚI NHẤT trong nhóm — hiển thị đúng phòng/khách đang thuê hiện tại, không
                // phải hợp đồng cũ đã hết hiệu lực từ trước khi chuyển phòng.
                $latestContract = $invoices->pluck('contract')->filter()->sortByDesc('start_date')->first();

                return [
                    'contract_id'   => $latestContract?->id,
                    'tenant'        => $latestContract?->tenant?->fullname ?? '—',
                    'room'          => $latestContract?->room?->code ?? '—',
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

    // Chuỗi thu/chi/lợi nhuận theo NGÀY (kỳ ngắn) hoặc THÁNG (kỳ dài) — mirror đúng ngưỡng
    // MAX_DAYS_FOR_DAILY_BREAKDOWN=62 của Modules\Dashboard\App\Services\Report\FinancialReportService
    // bên Home, để 2 bên nhất quán khi FE dùng chung 1 component biểu đồ cho cả Home/MiniHouse.
    // paid_at/transaction_date đều là cột `date` thật (không có phần giờ) nên pluck thẳng theo cột,
    // không cần bọc DATE() như bản Home (bản Home nhóm theo created_at là datetime).
    private const MAX_DAYS_FOR_DAILY_BREAKDOWN = 62;

    /**
     * @param int[] $buildingIds
     */
    public static function timeSeries(array $buildingIds, Carbon $start, Carbon $end): array
    {
        $collectedRows = self::scopeInvoicePaymentToBuilding(
            InvoicePayment::query()
                ->where('status', InvoicePayment::STATUS_APPROVED)
                ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()]),
            $buildingIds,
        )
            ->selectRaw('paid_at as d, SUM(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $expenseRows = Transaction::query()
            ->where('type', Transaction::TYPE_OUT)
            ->whereIn('building_id', $buildingIds)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('transaction_date as d, SUM(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $totalDays = max(1, (int) $start->diffInDays($end) + 1);
        $byMonth   = $totalDays > self::MAX_DAYS_FOR_DAILY_BREAKDOWN;

        if ($byMonth) {
            $monthly = [];
            foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
                $key = $day->format('Y-m');
                $d   = $day->toDateString();
                $monthly[$key]['collected'] = ($monthly[$key]['collected'] ?? 0) + (float) ($collectedRows[$d] ?? 0);
                $monthly[$key]['expense']   = ($monthly[$key]['expense'] ?? 0) + (float) ($expenseRows[$d] ?? 0);
            }

            return collect($monthly)
                ->map(fn ($v, $key) => ['date' => $key, 'collected' => $v['collected'], 'expense' => $v['expense'], 'profit' => $v['collected'] - $v['expense']])
                ->values()
                ->all();
        }

        $series = [];
        foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
            $d         = $day->toDateString();
            $collected = (float) ($collectedRows[$d] ?? 0);
            $expense   = (float) ($expenseRows[$d] ?? 0);
            $series[]  = ['date' => $d, 'collected' => $collected, 'expense' => $expense, 'profit' => $collected - $expense];
        }

        return $series;
    }

    // Chi phí trong kỳ, gộp theo nhóm (Transaction::CATEGORY_*) — dữ liệu đã sẵn có ở cột `category`,
    // trước đây báo cáo financial() chỉ trả 1 số expense tổng, không biết chi vào việc gì nhiều nhất.
    private const CATEGORY_LABELS = [
        Transaction::CATEGORY_REPAIR         => 'Sửa chữa',
        Transaction::CATEGORY_OPERATION      => 'Vận hành',
        Transaction::CATEGORY_DEPOSIT_REFUND => 'Hoàn cọc',
        Transaction::CATEGORY_OTHER          => 'Khác',
    ];

    /**
     * @param int[] $buildingIds
     */
    public static function expenseByCategory(array $buildingIds, Carbon $start, Carbon $end): array
    {
        $rows = Transaction::query()
            ->where('type', Transaction::TYPE_OUT)
            ->whereIn('building_id', $buildingIds)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        return collect(self::CATEGORY_LABELS)
            ->map(fn (string $label, string $category) => [
                'category' => $category,
                'label'    => $label,
                'amount'   => (float) ($rows[$category] ?? 0),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    // "Đã thu" trong kỳ, gộp theo phương thức thanh toán (InvoicePayment::METHOD_*) — trả lời câu hỏi
    // "khách trả tiền mặt hay chuyển khoản nhiều hơn", tương đương payment_methods.cash/transfer của
    // Home's EndOfDayReportService.
    private const PAYMENT_METHOD_LABELS = [
        InvoicePayment::METHOD_CASH     => 'Tiền mặt',
        InvoicePayment::METHOD_TRANSFER => 'Chuyển khoản',
        InvoicePayment::METHOD_OTHER    => 'Khác',
    ];

    /**
     * @param int[] $buildingIds
     */
    public static function collectedByPaymentMethod(array $buildingIds, Carbon $start, Carbon $end): array
    {
        $rows = self::scopeInvoicePaymentToBuilding(
            InvoicePayment::query()
                ->where('status', InvoicePayment::STATUS_APPROVED)
                ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()]),
            $buildingIds,
        )
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        return collect(self::PAYMENT_METHOD_LABELS)
            ->map(fn (string $label, string $method) => [
                'method' => $method,
                'label'  => $label,
                'amount' => (float) ($rows[$method] ?? 0),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }
}
