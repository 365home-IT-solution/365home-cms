<?php

namespace Modules\Minihouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;

// Dựng đủ CÁC TÌNH HUỐNG khác nhau trên sơ đồ phòng (RoomOccupancyMapWidget ở Dashboard) để xem
// trực quan mọi màu/trạng thái mà không phải tự tạo tay từng hợp đồng/hoá đơn: phòng trống, phòng
// đang sửa, phòng đang thuê đủ tiền, còn nợ 1 phần, chưa trả đồng nào, nợ dồn nhiều tháng, hợp đồng
// sắp hết hạn, và 1 cặp phòng minh hoạ "Chuyển phòng" (phòng cũ đã trả, phòng mới đang ở).
//
// PHỤ THUỘC BuildingRoomSeeder (chạy trước, tạo sẵn Toà nhà A/B/C + phòng) — seeder này CHỈ gắn thêm
// Khách thuê/Hợp đồng/Hoá đơn lên các phòng có sẵn, không tự tạo phòng mới. An toàn chạy lại nhiều
// lần: mỗi khối scenario tự kiểm tra phòng ĐÃ có hợp đồng "Đang hiệu lực" chưa trước khi tạo thêm,
// tránh tạo trùng hợp đồng mỗi lần chạy.
//
// Chạy tay: php artisan db:seed --class="Modules\Minihouse\Database\Seeders\RoomOccupancyDemoSeeder"
class RoomOccupancyDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BuildingRoomSeeder::class);

        $buildingA = Building::where('name', 'Toà nhà A - Quận 1')->firstOrFail();
        $buildingB = Building::where('name', 'Toà nhà B - Quận 3')->firstOrFail();

        // A-01 cố tình KHÔNG đụng tới — giữ nguyên "Trống" mặc định từ BuildingRoomSeeder.

        $this->markRepair($buildingA, 'A-02');

        $this->seedFullyPaid($buildingA, 'A-03', 'Nguyễn Văn An');
        $this->seedPartiallyPaid($buildingA, 'A-04', 'Trần Thị Bình');
        $this->seedUnpaid($buildingA, 'A-05', 'Lê Hoàng Cường');
        $this->seedAccumulatedDebt($buildingA, 'A-06', 'Phạm Thị Dung');
        $this->seedExpiringSoon($buildingA, 'A-07', 'Võ Minh Em');

        $this->seedTransferPair(
            fromBuilding: $buildingB,
            fromRoomCode: 'B-01',
            toBuilding: $buildingA,
            toRoomCode: 'A-08',
            tenantName: 'Đặng Thị Phương'
        );

        // Toà nhà C và các phòng còn lại của B cố tình để "Trống" hết — minh hoạ 1 toà đang vắng
        // khách so với toà A đang kín phòng, test bộ lọc theo toà nhà trên sơ đồ.

        $this->command?->info('RoomOccupancyDemoSeeder: đã dựng đủ tình huống trên sơ đồ phòng (trống/đang sửa/đủ tiền/nợ 1 phần/chưa trả/nợ dồn/sắp hết hạn/chuyển phòng).');
    }

    private function markRepair(Building $building, string $code): void
    {
        Room::where('building_id', $building->id)->where('code', $code)
            ->update(['status' => Room::STATUS_REPAIR]);
    }

    private function findRoom(Building $building, string $code): Room
    {
        return Room::where('building_id', $building->id)->where('code', $code)->firstOrFail();
    }

    private function makeTenant(string $fullname): Tenant
    {
        return Tenant::firstOrCreate(
            ['fullname' => $fullname, 'phone' => $this->phoneFor($fullname)],
            [
                'gender'         => Tenant::GENDER_MALE,
                'date_of_birth'  => Carbon::now()->subYears(25),
                'hometown'       => 'Việt Nam',
                'occupation'     => 'Nhân viên văn phòng',
            ]
        );
    }

    // Số điện thoại giả nhưng ỔN ĐỊNH theo tên — chạy lại seeder nhiều lần vẫn ra đúng 1 khách,
    // không tạo trùng qua firstOrCreate(['fullname', 'phone']).
    private function phoneFor(string $fullname): string
    {
        return '09' . str_pad((string) (crc32($fullname) % 100000000), 8, '0', STR_PAD_LEFT);
    }

    private function hasActiveContract(Room $room): bool
    {
        return Contract::where('room_id', $room->id)->where('status', Contract::STATUS_ACTIVE)->exists();
    }

    private function createContract(Room $room, Tenant $tenant, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'room_id'        => $room->id,
            'tenant_id'      => $tenant->id,
            'start_date'     => Carbon::now()->subMonths(3)->startOfMonth(),
            'end_date'       => Carbon::now()->addYear(),
            'monthly_price'  => $room->price,
            'deposit_amount' => $room->price,
            'status'         => Contract::STATUS_ACTIVE,
        ], $overrides));
    }

    private function createInvoice(Contract $contract, Carbon $month, array $overrides = []): Invoice
    {
        $roomPrice = $contract->monthly_price;

        return Invoice::create(array_merge([
            'contract_id'  => $contract->id,
            'month'        => $month->copy()->startOfMonth(),
            'period_start' => $month->copy()->startOfMonth(),
            'period_end'   => $month->copy()->endOfMonth(),
            'room_price'   => $roomPrice,
            'service_amount' => 0,
            'total_amount' => $roomPrice,
            'status'       => Invoice::STATUS_UNPAID,
        ], $overrides));
    }

    private function pay(Invoice $invoice, float $amount, ?Carbon $paidAt = null): void
    {
        InvoicePayment::create([
            'invoice_id'     => $invoice->id,
            'amount'         => $amount,
            'paid_at'        => $paidAt ?? now(),
            'payment_method' => InvoicePayment::METHOD_TRANSFER,
            'note'           => 'Dữ liệu mẫu RoomOccupancyDemoSeeder',
        ]);
    }

    // Phòng đang thuê, hoá đơn tháng này đã trả ĐỦ — sơ đồ hiện màu "đang thuê, đủ tiền".
    private function seedFullyPaid(Building $building, string $code, string $tenantName): void
    {
        $room = $this->findRoom($building, $code);

        if ($this->hasActiveContract($room)) {
            return;
        }

        $tenant   = $this->makeTenant($tenantName);
        $contract = $this->createContract($room, $tenant);
        $invoice  = $this->createInvoice($contract, now());

        $this->pay($invoice, $invoice->total_amount);
    }

    // Phòng đang thuê, hoá đơn tháng này mới trả 1 phần — sơ đồ hiện màu "còn nợ" kèm số tiền nợ.
    private function seedPartiallyPaid(Building $building, string $code, string $tenantName): void
    {
        $room = $this->findRoom($building, $code);

        if ($this->hasActiveContract($room)) {
            return;
        }

        $tenant   = $this->makeTenant($tenantName);
        $contract = $this->createContract($room, $tenant);
        $invoice  = $this->createInvoice($contract, now());

        $this->pay($invoice, round($invoice->total_amount * 0.4, -3));
    }

    // Phòng đang thuê, hoá đơn tháng này CHƯA trả đồng nào — không tạo InvoicePayment nào cả.
    private function seedUnpaid(Building $building, string $code, string $tenantName): void
    {
        $room = $this->findRoom($building, $code);

        if ($this->hasActiveContract($room)) {
            return;
        }

        $tenant   = $this->makeTenant($tenantName);
        $contract = $this->createContract($room, $tenant);

        $this->createInvoice($contract, now());
    }

    // Phòng đang thuê, nợ DỒN 3 tháng liên tiếp — minh hoạ công nợ tích luỹ (khác với "chưa trả
    // tháng này" ở seedUnpaid) để test đúng cột "Tổng nợ" trên FinanceReports/sơ đồ.
    private function seedAccumulatedDebt(Building $building, string $code, string $tenantName): void
    {
        $room = $this->findRoom($building, $code);

        if ($this->hasActiveContract($room)) {
            return;
        }

        $tenant   = $this->makeTenant($tenantName);
        $contract = $this->createContract($room, $tenant, [
            'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
        ]);

        for ($i = 2; $i >= 0; $i--) {
            $this->createInvoice($contract, now()->copy()->subMonths($i));
        }
    }

    // Phòng đang thuê, đã trả đủ hoá đơn hiện tại NHƯNG hợp đồng sắp hết hạn trong 10 ngày — test
    // ExpiringContractsWidget đồng thời với sơ đồ phòng.
    private function seedExpiringSoon(Building $building, string $code, string $tenantName): void
    {
        $room = $this->findRoom($building, $code);

        if ($this->hasActiveContract($room)) {
            return;
        }

        $tenant   = $this->makeTenant($tenantName);
        $contract = $this->createContract($room, $tenant, [
            'start_date' => Carbon::now()->subMonths(11),
            'end_date'   => Carbon::now()->addDays(10),
        ]);
        $invoice = $this->createInvoice($contract, now());

        $this->pay($invoice, $invoice->total_amount);
    }

    // Minh hoạ "Chuyển phòng": hợp đồng CŨ trên phòng đi đã "Hết hạn" (transferred_to trỏ sang hợp
    // đồng mới), phòng đó tự về "Trống"; hợp đồng MỚI trên phòng đến đang "Đang hiệu lực"
    // (transferred_from trỏ ngược lại) — dựng thẳng bằng Eloquent (KHÔNG gọi qua action Livewire
    // EditContract::transferRoom, vì action đó cần context 1 trang đang mở, không chạy được từ
    // console) nhưng giữ ĐÚNG kết quả cuối cùng y hệt bấm nút "Chuyển phòng" thật.
    private function seedTransferPair(Building $fromBuilding, string $fromRoomCode, Building $toBuilding, string $toRoomCode, string $tenantName): void
    {
        $fromRoom = $this->findRoom($fromBuilding, $fromRoomCode);
        $toRoom   = $this->findRoom($toBuilding, $toRoomCode);

        if ($this->hasActiveContract($toRoom) || Contract::where('room_id', $fromRoom->id)->exists()) {
            return;
        }

        $tenant = $this->makeTenant($tenantName);

        $oldContract = $this->createContract($fromRoom, $tenant, [
            'start_date'  => Carbon::now()->subMonths(4)->startOfMonth(),
            'end_date'    => Carbon::now()->addMonths(8),
            'status'      => Contract::STATUS_EXPIRED,
            'checkout_at' => Carbon::now()->subDays(5),
        ]);

        $newContract = $this->createContract($toRoom, $tenant, [
            'start_date'                    => Carbon::now()->subDays(5),
            'end_date'                      => $oldContract->end_date,
            'transferred_from_contract_id'  => $oldContract->id,
        ]);

        $oldContract->update(['transferred_to_contract_id' => $newContract->id]);

        $invoice = $this->createInvoice($newContract, now());
        $this->pay($invoice, $invoice->total_amount);

        // Phòng cũ tự về "Trống" qua ContractObserver khi status=expired được lưu ở trên — không cần
        // tự cập nhật tay ở đây.
    }
}
