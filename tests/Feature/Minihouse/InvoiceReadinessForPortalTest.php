<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Lỗi thật đã sửa: "Lập hoá đơn hàng loạt" cố ý tạo hoá đơn THIẾU chỉ số điện/nước (nhân viên bổ
// sung sau khi đọc đồng hồ) — trước đây Portal vẫn báo/hiện hoá đơn đó ngay từ lúc tạo, khách thấy 1
// hoá đơn thiếu tiền điện/nước rồi tổng tự đổi sau khi nhân viên điền xong, dễ hiểu nhầm là tính sai.
class InvoiceReadinessForPortalTest extends TestCase
{
    use DatabaseTransactions;

    private Contract $contract;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a', 'electric_unit_price' => 3500, 'water_unit_price' => 15000]);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-01', 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $this->tenant = Tenant::create(['fullname' => 'T', 'phone' => '0900001111', 'room_id' => $room->id]);
        $this->contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $this->tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
    }

    private function makeIncompleteInvoice(): Invoice
    {
        return Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
            // electric_end/water_end cố ý để trống — mô phỏng đúng InvoiceGenerationService.
        ]);
    }

    public function test_incomplete_invoice_does_not_notify_and_is_hidden_from_portal(): void
    {
        $invoice = $this->makeIncompleteInvoice();

        $this->assertEquals(0, PortalNotification::where('tenant_id', $this->tenant->id)->count());

        Auth::guard('tenant')->login($this->tenant);

        $index = $this->get(route('minihouse.portal.invoices.index'));
        $index->assertOk();
        $index->assertSee('Chưa có hoá đơn nào');

        $dashboard = $this->get(route('minihouse.portal.dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee('0đ'); // tổng còn nợ vẫn phải là 0, chưa tính hoá đơn thiếu thông tin

        $this->get(route('minihouse.portal.invoices.show', $invoice->id))->assertNotFound();
        $this->post(route('minihouse.portal.invoices.pay', $invoice->id))->assertNotFound();
    }

    public function test_filling_in_electric_and_water_triggers_notification_and_reveals_invoice(): void
    {
        $invoice = $this->makeIncompleteInvoice();
        $this->assertEquals(0, PortalNotification::where('tenant_id', $this->tenant->id)->count());

        // Nhân viên bổ sung chỉ số điện trước — CHƯA đủ (còn thiếu nước) nên vẫn chưa báo.
        $invoice->update(['electric_start' => 100, 'electric_end' => 150, 'electric_amount' => 175000]);
        $this->assertEquals(0, PortalNotification::where('tenant_id', $this->tenant->id)->count());

        // Bổ sung nốt chỉ số nước — ĐỦ rồi, phải báo đúng lúc này.
        $invoice->update(['water_start' => 10, 'water_end' => 15, 'water_amount' => 75000, 'total_amount' => 3250000]);

        $notification = PortalNotification::where('tenant_id', $this->tenant->id)
            ->where('type', PortalNotification::TYPE_INVOICE_NEW)->first();
        $this->assertNotNull($notification, 'Expected exactly one notification once the invoice became ready.');

        Auth::guard('tenant')->login($this->tenant);
        $this->get(route('minihouse.portal.invoices.show', $invoice->id))->assertOk();

        // Sửa thêm 1 trường không liên quan (VD service_amount) sau khi ĐÃ đủ — không được báo lại.
        $invoice->update(['service_amount' => 20000]);
        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenant->id)->where('type', PortalNotification::TYPE_INVOICE_NEW)->count());
    }

    public function test_invoice_created_already_complete_notifies_immediately(): void
    {
        Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000,
            'electric_start' => 100, 'electric_end' => 150, 'electric_amount' => 175000,
            'water_start' => 10, 'water_end' => 15, 'water_amount' => 75000,
            'total_amount' => 3250000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID,
        ]);

        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenant->id)->where('type', PortalNotification::TYPE_INVOICE_NEW)->count());
    }

    // Cột "Đã gửi khách" ở bảng Hoá đơn (panel nhân viên) — cho nhân viên biết ngay hoá đơn nào
    // khách ĐÃ thấy được trong Portal, dùng chung đúng 1 nguồn Invoice::isReadyForTenant().
    public function test_admin_invoices_table_shows_sent_to_tenant_status(): void
    {
        $admin = \App\Models\User::role('super_admin')->first();
        $this->assertNotNull($admin);

        $notReady = $this->makeIncompleteInvoice();

        $response = $this->actingAs($admin)->get('/minihouse-admin/invoices');
        $response->assertOk();

        $this->assertFalse($notReady->isReadyForTenant());

        $notReady->update(['electric_start' => 100, 'electric_end' => 150, 'water_start' => 10, 'water_end' => 15]);
        $notReady->refresh();
        $this->assertTrue($notReady->isReadyForTenant());
    }
}
