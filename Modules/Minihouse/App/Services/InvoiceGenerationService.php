<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;

// Lập hoá đơn HÀNG LOẠT cho tất cả hợp đồng "Đang hiệu lực" của 1 tháng — dùng chung công thức tiền
// phòng (prorate theo số ngày thực tế trong kỳ, kể cả hợp đồng mới bắt đầu/kết thúc giữa tháng) với
// InvoiceForm::recalcRoomPrice(), và cùng cách lấy đơn giá điện/nước + phụ thu định kỳ với
// InvoiceForm::fillUtilityDefaults()/fillItemsFromContract() — KHÔNG viết lại logic, chỉ khác chỗ
// InvoiceForm tính lúc nhân viên đang gõ form (1 hợp đồng), còn service này tính cho NHIỀU hợp đồng
// cùng lúc, không qua form.
//
// Chỉ số điện/nước KHÔNG tự đoán được (phải đọc đồng hồ thật) — để trống electric_end/water_end
// (thành tiền điện/nước = 0), nhân viên tự bổ sung sau khi đọc đồng hồ. electric_start/water_start
// tự nối tiếp từ electric_end/water_end của hoá đơn GẦN NHẤT của đúng hợp đồng đó (đúng thực tế vận
// hành: số cuối kỳ trước = số đầu kỳ này), không bắt nhập lại tay.
class InvoiceGenerationService
{
    /**
     * @param  array<int>|null  $buildingIds  Giới hạn theo toà nhà — null = tất cả (đã lọc theo
     *                                         ActiveBuildingScope ở tầng Contract::query() nếu có).
     * @return array{created: Collection<int, Invoice>, skipped: Collection<int, Contract>}
     */
    public static function generateForMonth(Carbon $month, ?array $buildingIds = null): array
    {
        $periodStart = $month->copy()->startOfMonth();
        $periodEnd   = $month->copy()->endOfMonth();

        $contracts = Contract::query()
            ->where('status', Contract::STATUS_ACTIVE)
            ->when(filled($buildingIds), fn ($q) => $q->whereHas('room', fn ($q2) => $q2->whereIn('building_id', $buildingIds)))
            // Hợp đồng đã kết thúc trước khi tháng này bắt đầu, hoặc chưa tới ngày bắt đầu — không
            // có ngày nào thuộc kỳ này để tính tiền phòng.
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $periodStart))
            ->whereDate('start_date', '<=', $periodEnd)
            ->with(['room.building'])
            ->get();

        $created = collect();
        $skipped = collect();

        foreach ($contracts as $contract) {
            $exists = Invoice::query()
                ->where('contract_id', $contract->id)
                ->whereYear('month', $periodStart->year)
                ->whereMonth('month', $periodStart->month)
                ->exists();

            if ($exists) {
                $skipped->push($contract);

                continue;
            }

            try {
                $created->push(static::buildInvoiceForContract($contract, $periodStart, $periodEnd));
            } catch (QueryException $e) {
                // Ràng buộc unique(contract_id, month) ở DB chặn trùng khi 2 lần lập hoá đơn hàng
                // loạt chạy chồng nhau (cron + bấm tay) — coi như bỏ qua giống trường hợp ->exists()
                // phát hiện được, không để crash cả lượt lập hoá đơn.
                if (! str_contains($e->getMessage(), 'minihouse_invoices_contract_month_unique')) {
                    throw $e;
                }

                $skipped->push($contract);
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public static function buildInvoiceForContract(Contract $contract, Carbon $periodStart, Carbon $periodEnd): Invoice
    {
        $effectiveStart = ($contract->start_date && $contract->start_date->gt($periodStart)) ? $contract->start_date->copy() : $periodStart->copy();
        $effectiveEnd   = ($contract->end_date && $contract->end_date->lt($periodEnd)) ? $contract->end_date->copy() : $periodEnd->copy();

        $daysInPeriod = $effectiveStart->diffInDays($effectiveEnd) + 1;
        $daysInMonth  = $periodStart->daysInMonth;

        $roomPrice = $daysInPeriod >= $daysInMonth
            ? (float) $contract->monthly_price
            : round(((float) $contract->monthly_price / $daysInMonth) * $daysInPeriod, 2);

        $electricPrice = $contract->electric_unit_price ?: $contract->room?->building?->electric_unit_price;
        $waterPrice    = $contract->water_unit_price ?: $contract->room?->building?->water_unit_price;

        // Nối tiếp chỉ số từ hoá đơn gần nhất (theo period_end) của đúng PHÒNG này — đồng hồ điện
        // nước gắn với phòng vật lý, không phải hợp đồng. Trước đây lọc theo contract_id nên khách
        // "Chuyển phòng" (đóng hợp đồng cũ, tạo hợp đồng mới trên phòng khác — xem
        // EditContract::getHeaderActions()) hoặc tenant mới thuê lại đúng phòng cũ đều bị mất nối
        // tiếp chỉ số (electric_start/water_start rơi về NULL dù đồng hồ vật lý vẫn đang chạy tiếp).
        $lastInvoice = Invoice::query()
            ->whereHas('contract', fn ($q) => $q->where('room_id', $contract->room_id))
            ->orderByDesc('period_end')
            ->first();

        $items = $contract->surcharges()->get()
            ->map(fn ($surcharge) => [
                'surcharge_id' => $surcharge->id,
                'name'         => $surcharge->name,
                'amount'       => $surcharge->amount,
            ])
            ->all();

        $serviceAmount = collect($items)->sum('amount');

        $invoice = Invoice::create([
            'contract_id'          => $contract->id,
            'month'                => $periodStart->copy()->startOfMonth(),
            'period_start'         => $effectiveStart,
            'period_end'           => $effectiveEnd,
            'room_price'           => $roomPrice,
            'electric_start'       => $lastInvoice?->electric_end,
            'electric_unit_price'  => $electricPrice,
            'water_start'          => $lastInvoice?->water_end,
            'water_unit_price'     => $waterPrice,
            'service_amount'       => round($serviceAmount, 2),
            'total_amount'         => round($roomPrice + $serviceAmount, 2),
            'status'               => Invoice::STATUS_UNPAID,
        ]);

        foreach ($items as $item) {
            $invoice->items()->create($item);
        }

        return $invoice;
    }
}
