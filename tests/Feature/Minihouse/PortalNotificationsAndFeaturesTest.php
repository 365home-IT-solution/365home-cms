<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Modules\Minihouse\App\Models\Announcement;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\TenantFeedback;
use Modules\Minihouse\App\Models\Zone;
use Modules\Minihouse\App\Services\ReminderNotificationService;
use Tests\TestCase;

class PortalNotificationsAndFeaturesTest extends TestCase
{
    use DatabaseTransactions;

    private Building $building;
    private Room $room;
    private Contract $contract;
    private Tenant $tenantPrimary;
    private Tenant $tenantOccupant;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $this->building = Building::create([
            'zone_id' => $zone->id, 'name' => 'Test Building', 'address' => 'a',
            'electric_unit_price' => 3500, 'water_unit_price' => 15000,
            'owner_name' => 'Chu Nha A', 'owner_phone' => '0900000000',
        ]);
        $this->room = Room::create(['building_id' => $this->building->id, 'code' => 'A-01', 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $this->tenantPrimary = Tenant::create(['fullname' => 'Primary', 'phone' => '0911111111', 'room_id' => $this->room->id]);
        $this->tenantOccupant = Tenant::create(['fullname' => 'Occupant', 'phone' => '0922222222', 'room_id' => $this->room->id]);

        $this->contract = Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $this->tenantPrimary->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
            'contract_file' => 'minihouse/contracts/fake-contract.pdf',
        ]);

        ContractTenant::create([
            'contract_id' => $this->contract->id,
            'tenant_id'   => $this->tenantOccupant->id,
            'role'        => ContractTenant::ROLE_OCCUPANT,
        ]);
    }

    public function test_new_invoice_notifies_all_tenants_on_the_contract(): void
    {
        $invoice = Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000,
            'electric_start' => 100, 'electric_end' => 150, 'water_start' => 10, 'water_end' => 15,
            'total_amount' => 3000000, 'amount_paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenantPrimary->id)
            ->where('type', PortalNotification::TYPE_INVOICE_NEW)->count());
        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenantOccupant->id)
            ->where('type', PortalNotification::TYPE_INVOICE_NEW)->count());

        $notification = PortalNotification::where('tenant_id', $this->tenantPrimary->id)->first();
        $this->assertEquals('/minihouse/portal/invoices/' . $invoice->id, $notification->link);
    }

    public function test_reminder_notify_creates_portal_notification_for_resolved_tenant(): void
    {
        Http::fake(); // don't actually hit Zalo/SMS

        $reminder = Reminder::create([
            'title' => 'Nhắc test', 'type' => Reminder::TYPE_PAYMENT,
            'contract_id' => $this->contract->id, 'remind_date' => now(),
        ]);

        ReminderNotificationService::notify($reminder);

        $notification = PortalNotification::where('tenant_id', $this->tenantPrimary->id)
            ->where('type', PortalNotification::TYPE_REMINDER)->first();

        $this->assertNotNull($notification);
        $this->assertEquals('Nhắc test', $notification->title);
    }

    public function test_feedback_reply_notifies_only_when_reviewed_flag_flips_true(): void
    {
        $feedback = TenantFeedback::create([
            'room_id' => $this->room->id, 'tenant_id' => $this->tenantPrimary->id,
            'tenant_name' => 'Primary', 'tenant_phone' => '0911111111',
            'rating' => 4, 'content' => 'Điều hoà kêu to',
        ]);

        $this->assertEquals(0, PortalNotification::where('tenant_id', $this->tenantPrimary->id)->count());

        $feedback->update(['staff_note' => 'Không phải là đã xử lý']); // is_reviewed unchanged
        $this->assertEquals(0, PortalNotification::where('tenant_id', $this->tenantPrimary->id)->count());

        $feedback->update(['is_reviewed' => true, 'staff_note' => 'Đã cử người kiểm tra']);

        $notification = PortalNotification::where('tenant_id', $this->tenantPrimary->id)
            ->where('type', PortalNotification::TYPE_FEEDBACK_REPLY)->first();
        $this->assertNotNull($notification);
        $this->assertEquals('Đã cử người kiểm tra', $notification->body);
    }

    public function test_anonymous_public_feedback_without_tenant_id_never_notifies(): void
    {
        $before = PortalNotification::count();

        $feedback = TenantFeedback::create([
            'room_id' => $this->room->id, 'rating' => 5, 'content' => 'Tốt',
        ]);

        $feedback->update(['is_reviewed' => true]);

        // Đếm CHÊNH LỆCH trước/sau, không đếm tuyệt đối — DB dev này là CHUNG với dữ liệu thật đang
        // dùng (VD chủ nhà đang test Announcement thật song song), không phải DB test cô lập.
        $this->assertEquals($before, PortalNotification::count());
    }

    public function test_announcement_for_specific_building_only_notifies_tenants_in_that_building(): void
    {
        $otherZone = Zone::create(['name' => 'Z2' . uniqid()]);
        $otherBuilding = Building::create(['zone_id' => $otherZone->id, 'name' => 'Other', 'address' => 'b', 'electric_unit_price' => 3500, 'water_unit_price' => 15000]);
        $otherRoom = Room::create(['building_id' => $otherBuilding->id, 'code' => 'B-01', 'price' => 2000000, 'status' => Room::STATUS_RENTED]);
        $otherTenant = Tenant::create(['fullname' => 'Other', 'phone' => '0933333333', 'room_id' => $otherRoom->id]);
        Contract::create([
            'room_id' => $otherRoom->id, 'tenant_id' => $otherTenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 2000000, 'deposit_amount' => 2000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        Announcement::create([
            'building_id' => $this->building->id,
            'title'       => 'Cắt nước ngày mai',
            'body'        => 'Từ 8h-12h ngày mai toà sẽ cắt nước để bảo trì.',
        ]);

        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenantPrimary->id)->where('type', PortalNotification::TYPE_ANNOUNCEMENT)->count());
        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenantOccupant->id)->where('type', PortalNotification::TYPE_ANNOUNCEMENT)->count());
        $this->assertEquals(0, PortalNotification::where('tenant_id', $otherTenant->id)->count());
    }

    public function test_announcement_for_all_buildings_notifies_every_active_tenant(): void
    {
        Announcement::create(['building_id' => null, 'title' => 'Thông báo chung', 'body' => 'Áp dụng mọi toà.']);

        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenantPrimary->id)->count());
        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenantOccupant->id)->count());
    }

    public function test_notifications_page_lists_and_marks_as_read(): void
    {
        PortalNotification::create(['tenant_id' => $this->tenantPrimary->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'Test', 'body' => 'x']);

        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->get(route('minihouse.portal.notifications'));
        $response->assertOk();
        $response->assertSee('Test');

        $this->assertEquals(0, PortalNotification::where('tenant_id', $this->tenantPrimary->id)->whereNull('read_at')->count());
    }

    public function test_authenticated_feedback_submission_is_linked_to_tenant(): void
    {
        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->post(route('minihouse.portal.feedback.store'), [
            'rating'  => 5,
            'content' => 'Rất tốt',
        ]);

        $response->assertRedirect(route('minihouse.portal.dashboard'));

        $feedback = TenantFeedback::where('tenant_id', $this->tenantPrimary->id)->first();
        $this->assertNotNull($feedback);
        $this->assertEquals('Primary', $feedback->tenant_name);
        $this->assertEquals($this->room->id, $feedback->room_id);
    }

    public function test_payment_history_only_shows_own_invoices(): void
    {
        $invoice = Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000,
            'electric_start' => 100, 'electric_end' => 150, 'water_start' => 10, 'water_end' => 15,
            'total_amount' => 3000000, 'amount_paid' => 3000000,
            'status' => Invoice::STATUS_PAID,
        ]);
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'amount' => 3000000, 'paid_at' => now(),
            'payment_method' => InvoicePayment::METHOD_TRANSFER, 'status' => InvoicePayment::STATUS_APPROVED,
        ]);

        // Another tenant's payment must NOT show up.
        $otherZone = Zone::create(['name' => 'Z3' . uniqid()]);
        $otherBuilding = Building::create(['zone_id' => $otherZone->id, 'name' => 'X', 'address' => 'c', 'electric_unit_price' => 3500, 'water_unit_price' => 15000]);
        $otherRoom = Room::create(['building_id' => $otherBuilding->id, 'code' => 'X-01', 'price' => 2000000, 'status' => Room::STATUS_RENTED]);
        $otherTenant = Tenant::create(['fullname' => 'Other', 'phone' => '0944444444', 'room_id' => $otherRoom->id]);
        $otherContract = Contract::create(['room_id' => $otherRoom->id, 'tenant_id' => $otherTenant->id, 'start_date' => now()->subMonth(), 'monthly_price' => 2000000, 'deposit_amount' => 2000000, 'status' => Contract::STATUS_ACTIVE]);
        $otherInvoice = Invoice::create(['contract_id' => $otherContract->id, 'month' => now()->startOfMonth(), 'room_price' => 2000000, 'total_amount' => 2000000, 'amount_paid' => 2000000, 'status' => Invoice::STATUS_PAID]);
        InvoicePayment::create(['invoice_id' => $otherInvoice->id, 'amount' => 2000000, 'paid_at' => now(), 'payment_method' => InvoicePayment::METHOD_CASH, 'status' => InvoicePayment::STATUS_APPROVED]);

        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->get(route('minihouse.portal.payments.index'));
        $response->assertOk();
        $response->assertSee('3.000.000');
        $response->assertDontSee('2.000.000');
    }

    public function test_contracts_index_and_show_with_file_download_link(): void
    {
        Auth::guard('tenant')->login($this->tenantPrimary);

        $index = $this->get(route('minihouse.portal.contracts.index'));
        $index->assertOk();
        $index->assertSee('A-01');

        $show = $this->get(route('minihouse.portal.contracts.show', $this->contract->id));
        $show->assertOk();
        $show->assertSee('Hợp đồng (file)');
    }

    public function test_tenant_cannot_view_another_tenants_contract(): void
    {
        $otherZone = Zone::create(['name' => 'Z4' . uniqid()]);
        $otherBuilding = Building::create(['zone_id' => $otherZone->id, 'name' => 'Y', 'address' => 'd', 'electric_unit_price' => 3500, 'water_unit_price' => 15000]);
        $otherRoom = Room::create(['building_id' => $otherBuilding->id, 'code' => 'Y-01', 'price' => 2000000, 'status' => Room::STATUS_RENTED]);
        $otherTenant = Tenant::create(['fullname' => 'Other', 'phone' => '0955555555', 'room_id' => $otherRoom->id]);
        $otherContract = Contract::create(['room_id' => $otherRoom->id, 'tenant_id' => $otherTenant->id, 'start_date' => now()->subMonth(), 'monthly_price' => 2000000, 'deposit_amount' => 2000000, 'status' => Contract::STATUS_ACTIVE]);

        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->get(route('minihouse.portal.contracts.show', $otherContract->id));
        $response->assertForbidden();
    }

    public function test_dashboard_shows_owner_contact_info(): void
    {
        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->get(route('minihouse.portal.dashboard'));
        $response->assertOk();
        $response->assertSee('Chu Nha A');
        $response->assertSee('0900000000');
    }

    public function test_invoice_card_badge_reflects_unpaid_count_and_clears_after_payment(): void
    {
        $invoice = Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000,
            'electric_start' => 100, 'electric_end' => 150, 'water_start' => 10, 'water_end' => 15,
            'total_amount' => 3000000, 'amount_paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        Auth::guard('tenant')->login($this->tenantPrimary);

        // Hoá đơn vừa tạo CŨNG tự bắn 1 thông báo Portal (xem InvoiceObserver) — nên trước khi thanh
        // toán có 2 badge đỏ (thẻ "Hoá đơn" VÀ thẻ "Thông báo"), đếm SỐ LƯỢNG thay vì chỉ kiểm tra có
        // xuất hiện hay không, để phân biệt đúng badge nào biến mất sau khi thanh toán.
        $before = preg_replace('/\s+/', ' ', $this->get(route('minihouse.portal.dashboard'))->getContent());
        $this->assertEquals(2, substr_count($before, 'bg-red-600'));

        // Ghi nhận thanh toán đủ — badge trên thẻ "Hoá đơn" phải BIẾN MẤT (không còn hoá đơn nào
        // unpaid/partial), CHỈ CÒN LẠI badge "Thông báo" (thông báo hoá đơn mới vẫn chưa đọc).
        $invoice->update(['amount_paid' => 3000000, 'status' => Invoice::STATUS_PAID]);

        $after = preg_replace('/\s+/', ' ', $this->get(route('minihouse.portal.dashboard'))->getContent());
        $this->assertEquals(1, substr_count($after, 'bg-red-600'));
    }

    public function test_unread_notification_badge_shows_in_layout(): void
    {
        PortalNotification::create(['tenant_id' => $this->tenantPrimary->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'X']);
        PortalNotification::create(['tenant_id' => $this->tenantPrimary->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'Y']);

        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->get(route('minihouse.portal.dashboard'));
        $response->assertOk();

        $collapsed = preg_replace('/\s+/', ' ', $response->getContent());
        $this->assertStringContainsString('bg-red-600', $collapsed);
        $this->assertStringContainsString('> 2 </span>', $collapsed);
    }

    public function test_announcement_admin_resource_page_loads(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        $this->actingAs($admin)
            ->get('/minihouse-admin/announcements')
            ->assertOk();
    }

    // Hoá đơn bị xoá mềm ở panel nhân viên (bấm "Xoá") KHÔNG được hiện lại/tính tiền cho khách trong
    // Portal — trước đây TenantPortalController dùng chung Invoice::withoutGlobalScopes() với các
    // model khác (Contract/Room), vô tình bỏ luôn cả bộ lọc SoftDeletes của CHÍNH Invoice, khiến hoá
    // đơn đã xoá vẫn hiện đủ nơi (dashboard, danh sách, chi tiết, cả cho phép thanh toán tiếp).
    public function test_soft_deleted_invoice_disappears_entirely_from_portal(): void
    {
        $invoice = Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
        ]);
        $invoiceId = $invoice->id;

        $invoice->delete(); // xoá mềm — đúng thao tác nhân viên bấm "Xoá" ở panel
        $this->assertTrue($invoice->trashed());

        Auth::guard('tenant')->login($this->tenantPrimary);

        // Dashboard: không được tính vào tổng còn nợ.
        $dashboard = $this->get(route('minihouse.portal.dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee('0đ'); // "Tổng còn phải thanh toán" phải về lại 0, không còn tính hoá đơn đã xoá

        // Danh sách hoá đơn: không được liệt kê.
        $index = $this->get(route('minihouse.portal.invoices.index'));
        $index->assertOk();
        $index->assertDontSee('3.000.000');

        // Xem/ thanh toán trực tiếp qua URL: phải 404, không được cho xem hay thanh toán tiếp.
        $this->get(route('minihouse.portal.invoices.show', $invoiceId))->assertNotFound();
        $this->post(route('minihouse.portal.invoices.pay', $invoiceId))->assertNotFound();
    }

    // Cùng lỗi lớp trên nhưng ở panel nhân viên — Select "Hoá đơn cần nhắc" khi tạo Nhắc việc không
    // được liệt kê hoá đơn đã xoá mềm để gắn nhầm 1 nhắc việc vào hoá đơn không còn tồn tại.
    public function test_deleted_invoice_does_not_appear_in_reminder_invoice_select_options(): void
    {
        $invoice = Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
        ]);
        $invoice->delete();

        $options = Invoice::where('contract_id', $this->contract->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->get();

        $this->assertTrue($options->isEmpty(), 'Deleted invoice must not be selectable for a new reminder.');
    }
}
