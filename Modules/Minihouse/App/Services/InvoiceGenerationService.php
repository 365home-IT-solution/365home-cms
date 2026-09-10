<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Minihouse\App\Models\Building;
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
    // $month CHỈ áp dụng cho toà "Theo tháng dương lịch" (Building::BILLING_CYCLE_CALENDAR_MONTH).
    // Toà "Theo ngày thuê" (BILLING_CYCLE_ANNIVERSARY) bỏ qua $month — luôn tự tính đúng chu kỳ kế
    // tiếp CỦA TỪNG hợp đồng dựa theo hoá đơn gần nhất/ngày bắt đầu hợp đồng (xem
    // generateDueAnniversaryInvoices()), vì "tháng" không có ý nghĩa cố định với kiểu này. Gộp
    // chung 1 hàm để nút "Lập hoá đơn hàng loạt"/cron chỉ cần gọi 1 chỗ, không phải tự phân biệt
    // toà nào theo kiểu nào.
    public static function generateForMonth(Carbon $month, ?array $buildingIds = null): array
    {
        $periodStart = $month->copy()->startOfMonth();
        $periodEnd   = $month->copy()->endOfMonth();

        $contracts = Contract::query()
            ->where('status', Contract::STATUS_ACTIVE)
            ->when(filled($buildingIds), fn ($q) => $q->whereHas('room', fn ($q2) => $q2->whereIn('building_id', $buildingIds)))
            ->whereHas('room.building', fn ($q) => $q->where(fn ($q2) => $q2
                ->whereNull('billing_cycle_type')
                ->orWhere('billing_cycle_type', Building::BILLING_CYCLE_CALENDAR_MONTH)))
            // Hợp đồng đã kết thúc trước khi tháng này bắt đầu, hoặc chưa tới ngày bắt đầu — không
            // có ngày nào thuộc kỳ này để tính tiền phòng.
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $periodStart))
            ->whereDate('start_date', '<=', $periodEnd)
            ->with(['room.building'])
            ->get();

        $created = collect();
        $skipped = collect();

        foreach ($contracts as $contract) {
            // Không còn ràng buộc unique(contract_id, month) ở DB nữa (đã bỏ — nó KHÔNG biết gì về
            // xoá mềm, khiến xoá 1 hoá đơn xong thì KHÔNG BAO GIỜ lập lại được cho đúng hợp đồng +
            // tháng đó nữa, xác nhận là bug thật). Khoá theo hợp đồng khi kiểm tra + tạo để 2 lượt
            // "Lập hoá đơn hàng loạt" chạy chồng nhau (cron + bấm tay) không cùng vượt qua exists()
            // rồi cùng tạo trùng — thay thế đúng vai trò ràng buộc DB cũ nhưng tôn trọng xoá mềm.
            $lock = Cache::lock('minihouse-invoice-gen:' . $contract->id, 10);

            if (! $lock->block(5)) {
                $skipped->push($contract);

                continue;
            }

            try {
                $exists = Invoice::query()
                    ->where('contract_id', $contract->id)
                    ->whereYear('month', $periodStart->year)
                    ->whereMonth('month', $periodStart->month)
                    ->exists();

                if ($exists) {
                    $skipped->push($contract);

                    continue;
                }

                $created->push(static::buildInvoiceForContract($contract, $periodStart, $periodEnd));
            } finally {
                $lock->release();
            }
        }

        $anniversaryResult = static::generateDueAnniversaryInvoices($buildingIds);

        return [
            'created' => $created->concat($anniversaryResult['created']),
            'skipped' => $skipped->concat($anniversaryResult['skipped']),
        ];
    }

    // Toà "Theo ngày thuê": mỗi hợp đồng có chu kỳ RIÊNG bắt đầu từ start_date, lặp lại mỗi tháng
    // theo đúng ngày đó (VD start_date=15/3 -> chu kỳ 15/3-14/4, 15/4-14/5,...) — không phụ thuộc
    // tháng dương lịch nào. Tự tính chu kỳ KẾ TIẾP dựa vào period_end của hoá đơn GẦN NHẤT (chưa có
    // hoá đơn nào thì bắt đầu từ start_date), lặp tạo bù nếu đã bỏ lỡ NHIỀU chu kỳ (lâu chưa chạy
    // lệnh) — có giới hạn 24 vòng/hợp đồng (2 năm) để tránh vòng lặp vô hạn nếu có dữ liệu bất thường.
    private static function generateDueAnniversaryInvoices(?array $buildingIds): array
    {
        $today = Carbon::today();

        $contracts = Contract::query()
            ->where('status', Contract::STATUS_ACTIVE)
            ->when(filled($buildingIds), fn ($q) => $q->whereHas('room', fn ($q2) => $q2->whereIn('building_id', $buildingIds)))
            ->whereHas('room.building', fn ($q) => $q->where('billing_cycle_type', Building::BILLING_CYCLE_ANNIVERSARY))
            ->whereDate('start_date', '<=', $today)
            ->with(['room.building'])
            ->get();

        $created = collect();
        $skipped = collect();

        foreach ($contracts as $contract) {
            // Khoá theo hợp đồng cho CẢ vòng lặp chu kỳ bù bên dưới — cùng lý do đã sửa ở
            // generateForMonth() (không còn ràng buộc unique DB nữa, chuyển hẳn sang khoá ứng dụng).
            $lock = Cache::lock('minihouse-invoice-gen:' . $contract->id, 30);

            if (! $lock->block(5)) {
                $skipped->push($contract);

                continue;
            }

            try {
                static::generateDueCyclesForContract($contract, $today, $created);
            } finally {
                $lock->release();
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private static function generateDueCyclesForContract(Contract $contract, Carbon $today, Collection $created): void
    {
        for ($i = 0; $i < 24; $i++) {
            $lastInvoice = Invoice::where('contract_id', $contract->id)->orderByDesc('period_end')->first();

            $cycleStart = $lastInvoice
                ? $lastInvoice->period_end->copy()->addDay()
                : $contract->start_date->copy();

            // Chưa tới ngày bắt đầu chu kỳ kế tiếp — dừng, hôm khác chạy lại sẽ tự bắt đúng lúc.
            if ($cycleStart->gt($today)) {
                break;
            }

            // Hợp đồng đã kết thúc trước khi chu kỳ này bắt đầu — không còn gì để lập nữa.
            if ($contract->end_date && $cycleStart->gt($contract->end_date)) {
                break;
            }

            $cycleEnd = $cycleStart->copy()->addMonthNoOverflow()->subDay();

            if ($contract->end_date && $cycleEnd->gt($contract->end_date)) {
                $cycleEnd = $contract->end_date->copy();
            }

            $created->push(static::buildInvoiceForContract($contract, $cycleStart, $cycleEnd));

            // Vừa lập tới đúng hoặc quá ngày kết thúc hợp đồng — không còn chu kỳ nào sau đó.
            if ($contract->end_date && $cycleEnd->gte($contract->end_date)) {
                break;
            }
        }
    }

    public static function buildInvoiceForContract(Contract $contract, Carbon $periodStart, Carbon $periodEnd): Invoice
    {
        $effectiveStart = ($contract->start_date && $contract->start_date->gt($periodStart)) ? $contract->start_date->copy() : $periodStart->copy();
        $effectiveEnd   = ($contract->end_date && $contract->end_date->lt($periodEnd)) ? $contract->end_date->copy() : $periodEnd->copy();

        $daysInPeriod = $effectiveStart->diffInDays($effectiveEnd) + 1;
        $daysInMonth  = $periodStart->daysInMonth;

        $roomPrice = $daysInPeriod >= $daysInMonth
            ? (float) $contract->monthly_price
            : round(((float) $contract->monthly_price / $daysInMonth) * $daysInPeriod, 0);

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
            'service_amount'       => round($serviceAmount, 0),
            'total_amount'         => round($roomPrice + $serviceAmount, 0),
            'status'               => Invoice::STATUS_UNPAID,
        ]);

        foreach ($items as $item) {
            $invoice->items()->create($item);
        }

        return $invoice;
    }
}
