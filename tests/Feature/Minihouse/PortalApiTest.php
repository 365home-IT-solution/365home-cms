<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Tests\TestCase;

class PortalApiTest extends TestCase
{
    use DatabaseTransactions;

    private Building $building;
    private Room $room;
    private Contract $contract;
    private Tenant $tenantPrimary;
    private Tenant $tenantSharedA;
    private Tenant $tenantSharedB;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->building = Building::create([
            'zone_id' => \Modules\Minihouse\App\Models\Zone::create(['name' => 'Z' . uniqid()])->id,
            'name' => 'B', 'address' => 'a', 'electric_unit_price' => 3500, 'water_unit_price' => 15000,
            'payment_method' => Building::PAYMENT_METHOD_VIETQR,
            'owner_bank_bin' => '970436', 'owner_bank_account_number' => '0123456789', 'owner_bank_account_holder' => 'A',
        ]);
        $this->room = Room::create(['building_id' => $this->building->id, 'code' => 'API-01', 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $this->tenantPrimary = Tenant::create(['fullname' => 'Primary', 'phone' => '0911000001', 'room_id' => $this->room->id]);
        $this->tenantSharedA = Tenant::create(['fullname' => 'Shared A', 'phone' => '0911000002', 'room_id' => $this->room->id]);
        $this->tenantSharedB = Tenant::create(['fullname' => 'Shared B', 'phone' => '0911000002', 'room_id' => $this->room->id]);

        $this->contract = Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $this->tenantPrimary->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
        ContractTenant::create(['contract_id' => $this->contract->id, 'tenant_id' => $this->tenantSharedA->id, 'role' => ContractTenant::ROLE_OCCUPANT]);
        ContractTenant::create(['contract_id' => $this->contract->id, 'tenant_id' => $this->tenantSharedB->id, 'role' => ContractTenant::ROLE_OCCUPANT]);

        $this->invoice = Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'electric_start' => 100, 'electric_end' => 150, 'water_start' => 10, 'water_end' => 15,
            'total_amount' => 3000000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID,
        ]);
    }

    public function test_otp_login_single_match_returns_token(): void
    {
        Cache::put('minihouse_tenant_otp:' . $this->tenantPrimary->phone, ['code' => '111111', 'attempts' => 0], now()->addMinutes(5));

        $response = $this->postJson('/api/minihouse/portal/otp/verify', ['phone' => $this->tenantPrimary->phone, 'code' => '111111']);
        $response->assertOk();
        $response->assertJsonStructure(['token', 'tenant' => ['id', 'fullname', 'phone']]);
        $this->assertEquals($this->tenantPrimary->id, $response->json('tenant.id'));
    }

    public function test_otp_login_shared_phone_requires_selection_and_rejects_tampered_id(): void
    {
        Cache::put('minihouse_tenant_otp:' . $this->tenantSharedA->phone, ['code' => '222222', 'attempts' => 0], now()->addMinutes(5));

        $verify = $this->postJson('/api/minihouse/portal/otp/verify', ['phone' => $this->tenantSharedA->phone, 'code' => '222222']);
        $verify->assertOk();
        $verify->assertJson(['requires_selection' => true]);
        $ticket = $verify->json('selection_ticket');
        $this->assertNotEmpty($ticket);

        $tampered = $this->postJson('/api/minihouse/portal/select-profile', ['selection_ticket' => $ticket, 'tenant_id' => $this->tenantPrimary->id]);
        $tampered->assertStatus(403);

        $legit = $this->postJson('/api/minihouse/portal/select-profile', ['selection_ticket' => $ticket, 'tenant_id' => $this->tenantSharedB->id]);
        $legit->assertOk();
        $this->assertEquals($this->tenantSharedB->id, $legit->json('tenant.id'));
    }

    public function test_password_login_and_logout(): void
    {
        $this->tenantPrimary->update(['password' => 'matkhau123']);

        $login = $this->postJson('/api/minihouse/portal/login/password', ['phone' => $this->tenantPrimary->phone, 'password' => 'matkhau123']);
        $login->assertOk();
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $dashboard = $this->withToken($token)->getJson('/api/minihouse/portal/dashboard');
        $dashboard->assertOk();

        $logout = $this->withToken($token)->postJson('/api/minihouse/portal/logout');
        $logout->assertOk();

        // Sanctum's guard instance memoizes the resolved user for the lifetime of the (shared,
        // within-this-test) container — a REAL request always gets a brand-new container/guard in
        // production, so this reset is purely a test-harness artifact, not something needed outside
        // tests. Without it, the next call below would incorrectly still "see" the just-logged-out
        // tenant instead of re-validating the (now-deleted) token against the database.
        $this->app['auth']->forgetGuards();

        $afterLogout = $this->withToken($token)->getJson('/api/minihouse/portal/dashboard');
        $afterLogout->assertStatus(401);
    }

    public function test_wrong_password_rejected_and_throttled(): void
    {
        $this->tenantPrimary->update(['password' => 'matkhau123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/minihouse/portal/login/password', ['phone' => $this->tenantPrimary->phone, 'password' => 'wrong'])->assertStatus(422);
        }

        $response = $this->postJson('/api/minihouse/portal/login/password', ['phone' => $this->tenantPrimary->phone, 'password' => 'matkhau123']);
        $response->assertStatus(429);
    }

    private function tokenFor(Tenant $tenant): string
    {
        return $tenant->createToken('test')->plainTextToken;
    }

    public function test_dashboard_shows_correct_data(): void
    {
        $token = $this->tokenFor($this->tenantPrimary);

        $response = $this->withToken($token)->getJson('/api/minihouse/portal/dashboard');
        $response->assertOk();
        $response->assertJsonPath('data.tenant.id', $this->tenantPrimary->id);
        $response->assertJsonPath('data.unpaid_invoice_count', 1);
        $response->assertJsonPath('data.active_contract.room_code', 'API-01');
    }

    public function test_invoices_list_and_show(): void
    {
        $token = $this->tokenFor($this->tenantPrimary);

        $index = $this->withToken($token)->getJson('/api/minihouse/portal/invoices');
        $index->assertOk();
        $index->assertJsonCount(1, 'data');

        $show = $this->withToken($token)->getJson('/api/minihouse/portal/invoices/' . $this->invoice->id);
        $show->assertOk();
        $show->assertJsonPath('data.id', $this->invoice->id);
    }

    // Test riêng (không gộp chung với test_invoices_list_and_show ở trên) — Sanctum's guard instance
    // memoizes the FIRST resolved user for the lifetime of a test's shared container; xác thực 2
    // danh tính KHÁC NHAU trong CÙNG 1 test method (dù bearer token gửi đi đúng khác nhau — đã xác
    // nhận qua log) vẫn trả về danh tính đầu tiên ở lần gọi thứ 2. Đây THUẦN TUÝ là hiện tượng riêng
    // của môi trường test (mỗi request thật trong production luôn có container hoàn toàn mới, không
    // hề có chuyện này) — tách method để mỗi test có $this->app RIÊNG (Laravel tự tạo lại cho mỗi
    // test) là cách né đúng tình huống này, không phải né 1 lỗi thật của code.
    public function test_tenant_cannot_view_another_tenants_invoice_via_api(): void
    {
        $otherTenant = Tenant::create(['fullname' => 'Other', 'phone' => '0999999999', 'room_id' => null]);
        $otherToken = $this->tokenFor($otherTenant);

        $this->withToken($otherToken)->getJson('/api/minihouse/portal/invoices/' . $this->invoice->id)->assertStatus(403);
    }

    public function test_incomplete_invoice_hidden_from_api(): void
    {
        $incomplete = Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->addMonth()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID,
        ]);

        $token = $this->tokenFor($this->tenantPrimary);

        $index = $this->withToken($token)->getJson('/api/minihouse/portal/invoices');
        $index->assertJsonCount(1, 'data'); // still just the ready one from setUp

        $this->withToken($token)->getJson('/api/minihouse/portal/invoices/' . $incomplete->id)->assertStatus(404);
    }

    public function test_pay_invoice_returns_vietqr_payment_payload(): void
    {
        $token = $this->tokenFor($this->tenantPrimary);

        $response = $this->withToken($token)->postJson('/api/minihouse/portal/invoices/' . $this->invoice->id . '/pay');
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['qr_image', 'amount', 'bank_info' => ['holder', 'bank', 'account']]]);
    }

    public function test_contracts_list_and_show(): void
    {
        $token = $this->tokenFor($this->tenantPrimary);

        $index = $this->withToken($token)->getJson('/api/minihouse/portal/contracts');
        $index->assertOk();
        $index->assertJsonCount(1, 'data');

        $show = $this->withToken($token)->getJson('/api/minihouse/portal/contracts/' . $this->contract->id);
        $show->assertOk();
        $show->assertJsonPath('data.id', $this->contract->id);
    }

    public function test_feedback_submission_via_api(): void
    {
        $token = $this->tokenFor($this->tenantPrimary);

        $response = $this->withToken($token)->postJson('/api/minihouse/portal/feedback', ['rating' => 5, 'content' => 'Tốt']);
        $response->assertCreated();

        $this->assertDatabaseHas('minihouse_tenant_feedbacks', ['tenant_id' => $this->tenantPrimary->id, 'rating' => 5]);
    }

    public function test_password_update_via_api(): void
    {
        $token = $this->tokenFor($this->tenantPrimary);

        $response = $this->withToken($token)->postJson('/api/minihouse/portal/password', [
            'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ]);
        $response->assertOk();

        $this->tenantPrimary->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpass123', $this->tenantPrimary->password));
    }

    // Token của App\Models\User (nhân viên) KHÔNG được dùng vào API Portal khách thuê, dù cả 2 dùng
    // chung Sanctum — xem TenantApiAuth.
    public function test_admin_user_token_cannot_access_tenant_portal_api(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/minihouse/portal/dashboard');
        $response->assertStatus(403);
    }

    // Ngược lại: token của Tenant KHÔNG được dùng vào API admin nội bộ.
    public function test_tenant_token_cannot_access_admin_api(): void
    {
        $token = $this->tokenFor($this->tenantPrimary);

        $response = $this->withToken($token)->getJson('/api/admin/minihouse/buildings');
        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/minihouse/portal/dashboard')->assertStatus(401);
    }
}
