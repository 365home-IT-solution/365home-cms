<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Modules\Minihouse\App\Services\InvoiceGenerationService;
use Tests\TestCase;

class InvoiceGenerationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function makeContract(string $billingCycle = Building::BILLING_CYCLE_CALENDAR_MONTH): Contract
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create([
            'zone_id' => $zone->id, 'name' => 'B', 'address' => 'a',
            'electric_unit_price' => 3500, 'water_unit_price' => 15000,
            'billing_cycle_type' => $billingCycle,
        ]);
        $room = Room::create(['building_id' => $building->id, 'code' => 'G-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '09' . random_int(10000000, 99999999), 'room_id' => $room->id]);

        return Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonths(2), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
    }

    // Lỗi thật: ràng buộc unique(contract_id, month) ở DB không biết gì về xoá mềm — xoá 1 hoá đơn
    // xong là KHÔNG BAO GIỜ lập lại được cho đúng hợp đồng + tháng đó nữa qua "Lập hoá đơn hàng loạt"
    // (đã xác nhận tái hiện thật trước khi sửa). Constraint đã bị bỏ (migration 2026_09_10_140000),
    // thay bằng khoá ứng dụng — test này đảm bảo không tái phát.
    public function test_can_regenerate_invoice_after_soft_deleting_the_original_calendar_month(): void
    {
        $contract = $this->makeContract(Building::BILLING_CYCLE_CALENDAR_MONTH);
        $month = now()->startOfMonth();

        $result1 = InvoiceGenerationService::generateForMonth($month, null);
        $this->assertCount(1, $result1['created']->filter(fn (Invoice $i) => $i->contract_id === $contract->id));

        $invoice = Invoice::where('contract_id', $contract->id)->first();
        $invoice->delete();
        $this->assertTrue($invoice->trashed());

        $result2 = InvoiceGenerationService::generateForMonth($month, null);
        $regenerated = $result2['created']->filter(fn (Invoice $i) => $i->contract_id === $contract->id);

        $this->assertCount(1, $regenerated, 'Expected a fresh invoice to be generated after the original was soft-deleted.');
        $this->assertEquals(1, Invoice::where('contract_id', $contract->id)->count());
    }

    public function test_skips_contract_that_already_has_an_active_invoice_for_the_month(): void
    {
        $contract = $this->makeContract(Building::BILLING_CYCLE_CALENDAR_MONTH);
        $month = now()->startOfMonth();

        InvoiceGenerationService::generateForMonth($month, null);
        $result2 = InvoiceGenerationService::generateForMonth($month, null);

        $this->assertTrue($result2['skipped']->contains(fn (Contract $c) => $c->id === $contract->id));
        $this->assertEquals(1, Invoice::where('contract_id', $contract->id)->count());
    }

    // Cùng lỗi lớp trên nhưng cho toà "Theo ngày thuê" (chu kỳ riêng theo start_date, không theo
    // tháng dương lịch) — cũng ghi vào cột `month` nên cũng dính đúng ràng buộc unique cũ.
    public function test_can_regenerate_invoice_after_soft_deleting_the_original_anniversary_cycle(): void
    {
        $contract = $this->makeContract(Building::BILLING_CYCLE_ANNIVERSARY);

        $result1 = InvoiceGenerationService::generateForMonth(now(), null);
        $firstBatch = $result1['created']->filter(fn (Invoice $i) => $i->contract_id === $contract->id);
        $this->assertGreaterThanOrEqual(1, $firstBatch->count());

        $lastInvoice = Invoice::where('contract_id', $contract->id)->orderByDesc('period_end')->first();
        $countBefore = Invoice::where('contract_id', $contract->id)->count();
        $lastInvoice->delete();

        $result2 = InvoiceGenerationService::generateForMonth(now(), null);
        $regenerated = $result2['created']->filter(fn (Invoice $i) => $i->contract_id === $contract->id);

        $this->assertGreaterThanOrEqual(1, $regenerated->count(), 'Expected the deleted cycle to be regenerated.');
        $this->assertEquals($countBefore, Invoice::where('contract_id', $contract->id)->count());
    }

    // Lỗi thật: tiền phòng prorate (monthly_price / số ngày trong tháng * số ngày ở) trước đây làm
    // tròn tới 2 SỐ LẺ (VD 2.772.333,33đ) — nhưng mọi nơi hiển thị (bảng Hoá đơn, Portal, form Ghi
    // nhận thanh toán) đều làm tròn xuống thành số nguyên "2.772.333đ" không hiện phần lẻ. Nhân viên
    // gõ đúng số ĐANG THẤY trên màn hình ("2772333") bị từ chối vì lệch 0,33đ với số THẬT lưu trong
    // CSDL — hoá đơn không thể nào ghi nhận thanh toán đủ được nữa. VNĐ không có đơn vị lẻ hơn đồng
    // nên đã đổi làm tròn tới SỐ NGUYÊN ngay từ lúc tính, xác nhận bằng test này.
    public function test_prorated_invoice_amounts_are_always_whole_dong_no_fractional_cents(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create([
            'zone_id' => $zone->id, 'name' => 'B', 'address' => 'a',
            'electric_unit_price' => 3500, 'water_unit_price' => 15000,
        ]);
        $room = Room::create(['building_id' => $building->id, 'code' => 'G-' . uniqid(), 'price' => 3700000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '09' . random_int(10000000, 99999999), 'room_id' => $room->id]);

        // start_date lệch ngày so với mùng 1 để CHẮC CHẮN kỳ hoá đơn tháng này bị prorate (không
        // tròn tháng) — đúng monthly_price đã gây ra số lẻ 0,33đ thật trong dữ liệu (3.700.000đ).
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->startOfMonth()->addDays(8), 'monthly_price' => 3700000, 'deposit_amount' => 3700000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        $invoice = InvoiceGenerationService::buildInvoiceForContract($contract, now()->startOfMonth(), now()->endOfMonth());

        $this->assertEquals((float) $invoice->room_price, floor((float) $invoice->room_price), 'room_price must be a whole đồng amount.');
        $this->assertEquals((float) $invoice->total_amount, floor((float) $invoice->total_amount), 'total_amount must be a whole đồng amount.');

        // Đúng luồng nhân viên thật: gõ số nguyên ĐANG HIỆN trên màn hình phải được chấp nhận.
        $error = $invoice->validateSinglePayment((float) number_format((float) $invoice->total_amount, 0, '', ''));
        $this->assertNull($error, 'Entering the exact whole-đồng amount shown on screen must be accepted.');
    }
}
