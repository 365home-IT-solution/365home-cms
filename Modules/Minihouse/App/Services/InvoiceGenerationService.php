<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Metering\App\Models\MeteringReading;
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
            // billing_cycle_type nằm ở bảng phụ minihouse_building_settings (uỷ quyền qua
            // Building::getAttribute(), xem Building.php) — KHÔNG PHẢI cột thật trên categories, nên
            // không lọc trực tiếp qua whereHas('room.building', ...) như trước được nữa (từng gây lỗi
            // "Unknown column billing_cycle_type"). Lọc qua subquery tới đúng bảng phụ đó.
            ->whereHas('room', fn ($q) => $q->whereIn('building_id', function ($q2) {
                $q2->select('category_id')->from('minihouse_building_settings')
                    ->where(fn ($q3) => $q3
                        ->whereNull('billing_cycle_type')
                        ->orWhere('billing_cycle_type', Building::BILLING_CYCLE_CALENDAR_MONTH));
            }))
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
            ->whereHas('room', fn ($q) => $q->whereIn('building_id', function ($q2) {
                $q2->select('category_id')->from('minihouse_building_settings')
                    ->where('billing_cycle_type', Building::BILLING_CYCLE_ANNIVERSARY);
            }))
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
        // BUG THẬT đã gặp: trước đây so $daysInPeriod với $periodStart->daysInMonth (số ngày của
        // THÁNG DƯƠNG LỊCH chứa periodStart) — với chu kỳ "theo ngày thuê" (BILLING_CYCLE_ANNIVERSARY,
        // cycleEnd = cycleStart->addMonthNoOverflow()->subDay()), 1 chu kỳ ĐẦY ĐỦ KHÔNG hề trùng số
        // ngày với tháng dương lịch chứa ngày bắt đầu — VD hợp đồng bắt đầu 31/1: chu kỳ đầy đủ chạy
        // 31/1 → 27/2 (28 ngày, addMonthNoOverflow từ 31/1 ra 28/2 rồi -1 ngày), nhưng
        // periodStart->daysInMonth (tháng 1) = 31, khiến code hiểu NHẦM là chu kỳ bị hụt 3 ngày và
        // tính giá theo tỷ lệ (2.709.677đ) dù khách đã ở TRỌN VẸN, KHÔNG NGẮT QUÃNG cả chu kỳ — thiếu
        // thu lặp lại ở MỌI chu kỳ sau đó của hợp đồng này. Sửa: so $daysInPeriod với chính số ngày
        // THẬT của chu kỳ đang lập ($periodStart..$periodEnd, trước khi bị cắt bởi ngày bắt đầu/kết
        // thúc hợp đồng) — 1 chu kỳ không bị cắt luôn có $daysInPeriod == $fullCycleDays bất kể rơi
        // vào tháng nào, chỉ prorate đúng phần bị cắt thật sự (hợp đồng bắt đầu/kết thúc giữa chu kỳ).
        $fullCycleDays = $periodStart->diffInDays($periodEnd) + 1;

        $roomPrice = $daysInPeriod >= $fullCycleDays
            ? (float) $contract->monthly_price
            : round(((float) $contract->monthly_price / $fullCycleDays) * $daysInPeriod, 0);

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

        // Module Metering (tách riêng, chỉ quản lý CHỈ SỐ — không quản lý giá) có thể đã có log chỉ
        // số điện/nước của đúng phòng + đúng tháng đang lập hoá đơn — ưu tiên dùng log đó (kể cả
        // electric_end/water_end, nhân viên đã đọc đồng hồ xong trước khi lập hoá đơn) thay vì luôn
        // để trống end như trước. Building/phòng CHƯA dùng module Metering (không có log tháng này)
        // thì giữ nguyên hành vi cũ — không breaking.
        $meteringReading = MeteringReading::forRoomAndMonth($contract->room_id, $periodStart);

        $items = $contract->surcharges()->get()
            ->map(fn ($surcharge) => [
                'surcharge_id' => $surcharge->id,
                'name'         => $surcharge->name,
                'amount'       => $surcharge->amount,
            ])
            ->all();

        $serviceAmount = collect($items)->sum('amount');

        $electricStart = $meteringReading?->electric_start ?? $lastInvoice?->electric_end;
        $electricEnd   = $meteringReading?->electric_end;
        $waterStart    = $meteringReading?->water_start ?? $lastInvoice?->water_end;
        $waterEnd      = $meteringReading?->water_end;

        // BẮT BUỘC tính electric_amount/water_amount NGAY LÚC TẠO — trước đây (khi end LUÔN null vì
        // chưa có module Metering) bỏ qua 2 field này không sao vì mặc định là 0 đúng thực tế "chưa
        // biết". Từ khi có module Metering, $meteringReading có thể đã cho biết ĐỦ end ngay lúc lập
        // hoá đơn hàng loạt — bỏ qua sẽ để electric_amount/water_amount = NULL/0 SAI dù thừa dữ liệu
        // để tính đúng, và total_amount cũng thiếu hẳn 2 khoản này (lỗi thật đã phát hiện: hoá đơn có
        // electric_end/water_end đầy đủ nhưng electric_amount/water_amount vẫn bằng 0).
        $electricAmount = ($electricEnd !== null && $electricStart !== null)
            ? round(max(0, (float) $electricEnd - (float) $electricStart) * (float) $electricPrice, 0)
            : 0;
        $waterAmount = ($waterEnd !== null && $waterStart !== null)
            ? round(max(0, (float) $waterEnd - (float) $waterStart) * (float) $waterPrice, 0)
            : 0;

        $invoice = Invoice::create([
            'contract_id'          => $contract->id,
            'month'                => $periodStart->copy()->startOfMonth(),
            'period_start'         => $effectiveStart,
            'period_end'           => $effectiveEnd,
            'room_price'           => $roomPrice,
            'electric_start'       => $electricStart,
            'electric_end'         => $electricEnd,
            'electric_unit_price'  => $electricPrice,
            'electric_amount'      => $electricAmount,
            'water_start'          => $waterStart,
            'water_end'            => $waterEnd,
            'water_unit_price'     => $waterPrice,
            'water_amount'         => $waterAmount,
            'service_amount'       => round($serviceAmount, 0),
            'total_amount'         => round($roomPrice + $electricAmount + $waterAmount + $serviceAmount, 0),
            'status'               => Invoice::STATUS_UNPAID,
        ]);

        foreach ($items as $item) {
            $invoice->items()->create($item);
        }

        return $invoice;
    }
}
