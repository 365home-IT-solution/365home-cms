<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\SmsSetting;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Modules\Minihouse\App\Models\ZaloSetting;
use Tests\TestCase;

class TenantPortalTest extends TestCase
{
    use DatabaseTransactions;

    private Building $building;
    private Room $room;
    private Contract $contract;
    private Tenant $tenantPrimary;
    private Tenant $tenantSharedPhoneA;
    private Tenant $tenantSharedPhoneB;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        // Cache::array KHÔNG bị cuốn theo rollback của DatabaseTransactions (nó sống trong bộ nhớ cả
        // tiến trình PHPUnit, không phải DB) — phải xoá tay đầu mỗi test, nếu không cooldown gửi lại
        // OTP 60s (TenantOtpService) của 1 test trước sẽ rò rỉ sang test sau dùng CHUNG số điện thoại.
        Cache::flush();

        $zone = Zone::create(['name' => 'Test Zone ' . uniqid()]);

        $this->building = Building::create([
            'zone_id' => $zone->id,
            'name' => 'Test Building',
            'address' => '123 Test St',
            'electric_unit_price' => 3500,
            'water_unit_price' => 15000,
            'payment_method' => Building::PAYMENT_METHOD_VIETQR,
            'owner_bank_bin' => '970436',
            'owner_bank_name' => 'Vietcombank',
            'owner_bank_account_number' => '0123456789',
            'owner_bank_account_holder' => 'NGUYEN VAN A',
        ]);

        $this->room = Room::create([
            'building_id' => $this->building->id,
            'code' => 'P101',
            'price' => 3000000,
            'status' => Room::STATUS_RENTED,
        ]);

        // Tenant đứng tên chính hợp đồng.
        $this->tenantPrimary = Tenant::create([
            'fullname' => 'Tenant Primary',
            'phone' => '0901111111',
            'room_id' => $this->room->id,
        ]);

        $this->contract = Contract::create([
            'room_id' => $this->room->id,
            'tenant_id' => $this->tenantPrimary->id,
            'start_date' => now()->subMonth(),
            'monthly_price' => 3000000,
            'deposit_amount' => 3000000,
            'electric_unit_price' => 3500,
            'water_unit_price' => 15000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        // 2 khách "ở cùng" (occupant) DÙNG CHUNG một số điện thoại — mô phỏng ca gia đình.
        $this->tenantSharedPhoneA = Tenant::create([
            'fullname' => 'Occupant A',
            'phone' => '0902222222',
            'room_id' => $this->room->id,
        ]);
        $this->tenantSharedPhoneB = Tenant::create([
            'fullname' => 'Occupant B',
            'phone' => '0902222222',
            'room_id' => $this->room->id,
        ]);
        ContractTenant::create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenantSharedPhoneA->id,
            'role' => ContractTenant::ROLE_OCCUPANT,
        ]);
        ContractTenant::create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenantSharedPhoneB->id,
            'role' => ContractTenant::ROLE_OCCUPANT,
        ]);

        $this->invoice = Invoice::create([
            'contract_id' => $this->contract->id,
            'month' => now()->startOfMonth(),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'room_price' => 3000000,
            // electric_end/water_end đã điền — hoá đơn này không phải đối tượng test của
            // InvoiceReadinessForPortalTest (đã có test riêng), các test khác trong file này giả
            // định hoá đơn hiện luôn trong Portal như trước khi thêm Invoice::isReadyForTenant().
            'electric_start' => 100, 'electric_end' => 150, 'electric_amount' => 0,
            'water_start' => 10, 'water_end' => 15, 'water_amount' => 0,
            'total_amount' => 3000000,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
        ]);
    }

    public function test_login_page_loads(): void
    {
        $response = $this->get(route('minihouse.portal.login'));
        $response->assertOk();
        $response->assertSee('Đăng nhập');
    }

    public function test_unknown_phone_is_rejected(): void
    {
        $response = $this
            ->from(route('minihouse.portal.login'))
            ->post(route('minihouse.portal.login.request-otp'), ['phone' => '0999999999']);
        $response->assertRedirect(route('minihouse.portal.login'));
        $response->assertSessionHasErrors('phone');
    }

    public function test_otp_falls_back_to_sms_when_zalo_not_configured(): void
    {
        Http::fake(['rest.esms.vn/*' => Http::response(['CodeResult' => '100', 'SMSID' => 'x'], 200)]);

        $smsSettings = SmsSetting::current();
        $smsSettings->fill([
            'api_key' => 'test_api_key',
            'secret_key' => 'test_secret_key',
            'brandname' => 'MINIHOUSE',
        ])->save();

        $response = $this
            ->from(route('minihouse.portal.login'))
            ->post(route('minihouse.portal.login.request-otp'), ['phone' => $this->tenantPrimary->phone]);

        $response->assertRedirect(route('minihouse.portal.login.verify'));
        $this->assertEquals($this->tenantPrimary->phone, session('minihouse_portal_login_phone'));

        $cached = Cache::get('minihouse_tenant_otp:' . $this->tenantPrimary->phone);
        $this->assertNotNull($cached, 'OTP code should be cached even though SMS is not configured (SmsSetting::isConfigured() is false, sendRaw should short-circuit and generateAndSend should report reason)');
    }

    public function test_otp_tries_zalo_first_when_configured(): void
    {
        Http::fake(['*openapi.zalo.me*' => Http::response(['error' => 0, 'data' => ['msg_id' => 'abc']], 200)]);

        $zaloSettings = ZaloSetting::current();
        $zaloSettings->fill([
            'app_id' => 'test_app',
            'app_secret' => 'test_secret',
            'access_token' => 'tok',
            'refresh_token' => 'rtok',
            'access_token_expires_at' => now()->addHour(),
            'template_otp' => '999999',
        ])->save();

        $response = $this->post(route('minihouse.portal.login.request-otp'), ['phone' => $this->tenantPrimary->phone]);

        $response->assertRedirect(route('minihouse.portal.login.verify'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'openapi.zalo.me');
        });
    }

    public function test_full_single_tenant_login_and_dashboard_flow(): void
    {
        Http::fake(['rest.esms.vn/*' => Http::response(['CodeResult' => '999', 'SMSID' => null], 200)]);
        // Zalo AND sms both fail/unconfigured -> service should report not-sent, not silently cache.
        $result = app(\Modules\Minihouse\App\Services\TenantOtpService::class)->generateAndSend($this->tenantPrimary->phone, $this->tenantPrimary->fullname);
        $this->assertFalse($result['sent']);

        // Now directly seed a known OTP the way the service would, to test the verify/login path
        // in isolation from delivery channels (already covered by the two tests above).
        Cache::put('minihouse_tenant_otp:' . $this->tenantPrimary->phone, ['code' => '123456', 'attempts' => 0], now()->addMinutes(5));
        session(['minihouse_portal_login_phone' => $this->tenantPrimary->phone]);

        $response = $this->post(route('minihouse.portal.login.verify.submit'), ['code' => '123456']);
        $response->assertRedirect(route('minihouse.portal.dashboard'));

        $this->assertTrue(Auth::guard('tenant')->check());
        $this->assertEquals($this->tenantPrimary->id, Auth::guard('tenant')->id());
        $this->assertFalse(Auth::guard('web')->check(), 'web guard must stay unaffected by tenant guard login');

        $dashboard = $this->get(route('minihouse.portal.dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee('Tenant Primary');
        $dashboard->assertSee('3.000.000');
    }

    public function test_wrong_otp_increments_attempts_and_shows_error(): void
    {
        Cache::put('minihouse_tenant_otp:' . $this->tenantPrimary->phone, ['code' => '111111', 'attempts' => 0], now()->addMinutes(5));
        session(['minihouse_portal_login_phone' => $this->tenantPrimary->phone]);

        $response = $this->post(route('minihouse.portal.login.verify.submit'), ['code' => '000000']);
        $response->assertSessionHasErrors('code');
        $this->assertFalse(Auth::guard('tenant')->check());

        $cached = Cache::get('minihouse_tenant_otp:' . $this->tenantPrimary->phone);
        $this->assertEquals(1, $cached['attempts']);
    }

    // Lỗi thật đã sửa: mỗi lần verify() sai mã trước đây tự GHI ĐÈ lại TTL đầy 5 phút mới, có thể
    // "gia hạn" 1 mã OTP hiệu lực xa hơn nhiều so với 5 phút dự kiến nếu đoán sai cách nhau vài phút.
    // Test này xác nhận mã vẫn hết hạn ĐÚNG 5 phút kể từ lúc gửi, dù có 1 lần đoán sai giữa chừng.
    public function test_otp_still_expires_on_schedule_despite_a_wrong_attempt_in_between(): void
    {
        \Illuminate\Support\Carbon::setTestNow(now());

        $otp = app(\Modules\Minihouse\App\Services\TenantOtpService::class);
        $cacheKey = 'minihouse_tenant_otp:' . $this->tenantPrimary->phone;

        Cache::put($cacheKey, ['code' => '123456', 'attempts' => 0, 'expires_at' => now()->addMinutes(5)->timestamp], now()->addMinutes(5));

        // Đoán sai sau 4 phút — TTL trước đây sẽ bị làm mới thành 5 phút KỂ TỪ ĐÂY (tức hết hạn ở
        // phút thứ 9), thay vì vẫn giữ đúng mốc hết hạn ban đầu (phút thứ 5).
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(4));
        $otp->verify($this->tenantPrimary->phone, '000000');

        // Tua tới phút thứ 6 (đã quá mốc hết hạn GỐC là phút thứ 5) — mã phải đã hết hạn, KHÔNG được
        // còn hiệu lực nhờ bị "gia hạn" ở bước đoán sai trên.
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(2));
        $result = $otp->verify($this->tenantPrimary->phone, '123456');

        \Illuminate\Support\Carbon::setTestNow();

        $this->assertFalse($result['valid'], 'OTP must expire on its original schedule, not be extended by a wrong guess in between.');
    }

    public function test_shared_phone_routes_to_select_profile_and_rejects_tampered_id(): void
    {
        Cache::put('minihouse_tenant_otp:' . $this->tenantSharedPhoneA->phone, ['code' => '222222', 'attempts' => 0], now()->addMinutes(5));
        session(['minihouse_portal_login_phone' => $this->tenantSharedPhoneA->phone]);

        $response = $this->post(route('minihouse.portal.login.verify.submit'), ['code' => '222222']);
        $response->assertRedirect(route('minihouse.portal.login.select-profile'));
        $this->assertFalse(Auth::guard('tenant')->check());

        $verifiedIds = session('minihouse_portal_verified_tenant_ids');
        $this->assertEqualsCanonicalizing([$this->tenantSharedPhoneA->id, $this->tenantSharedPhoneB->id], $verifiedIds);

        // Tamper: try to select a tenant ID that is NOT in the whitelist (e.g. the primary tenant,
        // who does not share this phone number) -> must be rejected with 403.
        $tamperResponse = $this->post(route('minihouse.portal.login.select-profile.submit'), [
            'tenant_id' => $this->tenantPrimary->id,
        ]);
        $tamperResponse->assertForbidden();
        $this->assertFalse(Auth::guard('tenant')->check());

        // Legitimate selection succeeds.
        $legitResponse = $this->post(route('minihouse.portal.login.select-profile.submit'), [
            'tenant_id' => $this->tenantSharedPhoneB->id,
        ]);
        $legitResponse->assertRedirect(route('minihouse.portal.dashboard'));
        $this->assertEquals($this->tenantSharedPhoneB->id, Auth::guard('tenant')->id());
    }

    public function test_unauthenticated_access_redirects_to_portal_login_not_admin_login(): void
    {
        $response = $this->get(route('minihouse.portal.dashboard'));
        $response->assertRedirect(route('minihouse.portal.login'));
    }

    public function test_occupant_sees_the_shared_contract_and_invoice(): void
    {
        Auth::guard('tenant')->login($this->tenantSharedPhoneA);

        $dashboard = $this->get(route('minihouse.portal.dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee('P101');

        $invoices = $this->get(route('minihouse.portal.invoices.index'));
        $invoices->assertOk();
        $invoices->assertSee('3.000.000');

        $show = $this->get(route('minihouse.portal.invoices.show', $this->invoice->id));
        $show->assertOk();
    }

    public function test_tenant_cannot_view_another_tenants_invoice(): void
    {
        $otherRoom = Room::create([
            'building_id' => $this->building->id,
            'code' => 'P202',
            'price' => 2500000,
            'status' => Room::STATUS_RENTED,
        ]);
        $otherTenant = Tenant::create([
            'fullname' => 'Other Tenant',
            'phone' => '0903333333',
            'room_id' => $otherRoom->id,
        ]);
        $otherContract = Contract::create([
            'room_id' => $otherRoom->id,
            'tenant_id' => $otherTenant->id,
            'start_date' => now()->subMonth(),
            'monthly_price' => 2500000,
            'deposit_amount' => 2500000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
        $otherInvoice = Invoice::create([
            'contract_id' => $otherContract->id,
            'month' => now()->startOfMonth(),
            'room_price' => 2500000,
            'total_amount' => 2500000,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->get(route('minihouse.portal.invoices.show', $otherInvoice->id));
        $response->assertForbidden();
    }

    public function test_pay_invoice_with_vietqr_building_shows_bank_qr(): void
    {
        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->post(route('minihouse.portal.invoices.pay', $this->invoice->id));
        $response->assertOk();
        $response->assertSee('Vietcombank');
        $response->assertSee('0123456789');
        $response->assertSee('img.vietqr.io', false);
    }

    public function test_pay_invoice_with_momo_building_returns_own_return_url_not_admin_page(): void
    {
        Http::fake(['test-payment.momo.vn/*' => Http::response([
            'resultCode' => 0,
            'payUrl'     => 'https://test-payment.momo.vn/pay/abc',
            'qrCodeUrl'  => '00020101021244780010A00000007701...momoqrpayload',
        ], 200)]);

        $this->building->update([
            'payment_method' => Building::PAYMENT_METHOD_MOMO,
            'payment_sandbox' => true,
            'momo_partner_code' => 'MOMO',
            'momo_access_key' => 'F8BBA842ECF85',
            'momo_secret_key' => 'K951B6PE1waDMi640xX08PD3vg6EkVlz',
        ]);

        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->post(route('minihouse.portal.invoices.pay', $this->invoice->id));
        $response->assertOk();

        // Đúng bug đã sửa: redirectUrl gửi cho MoMo phải là trang Portal, KHÔNG phải trang Sửa hoá
        // đơn của panel nhân viên (tenant không có quyền vào panel đó, guard "web" khác "tenant").
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'test-payment.momo.vn')
                && isset($body['redirectUrl'])
                && str_contains($body['redirectUrl'], 'minihouse/portal/invoices/' . $this->invoice->id)
                && ! str_contains($body['redirectUrl'], 'minihouse-admin');
        });
    }

    public function test_pay_invoice_with_vnpay_building_returns_own_return_url_not_admin_page(): void
    {
        $this->building->update([
            'payment_method' => Building::PAYMENT_METHOD_VNPAY,
            'payment_sandbox' => true,
            'vnpay_tmn_code' => 'TESTTMN01',
            'vnpay_hash_secret' => 'TESTHASHSECRET123',
        ]);

        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->post(route('minihouse.portal.invoices.pay', $this->invoice->id));
        $response->assertOk();

        $this->invoice->refresh();
        $this->assertStringContainsString('sandbox.vnpayment.vn', $this->invoice->vnpay_payment_url);

        // Decode vnp_ReturnUrl embedded in the generated payment URL and confirm it points at the
        // Portal's own invoice page, not the staff admin edit page.
        parse_str(parse_url($this->invoice->vnpay_payment_url, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('minihouse/portal/invoices/' . $this->invoice->id, $query['vnp_ReturnUrl']);
        $this->assertStringNotContainsString('minihouse-admin', $query['vnp_ReturnUrl']);
    }

    public function test_pay_already_paid_invoice_redirects_with_info(): void
    {
        $this->invoice->update(['amount_paid' => 3000000, 'status' => Invoice::STATUS_PAID]);

        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->post(route('minihouse.portal.invoices.pay', $this->invoice->id));
        $response->assertRedirect(route('minihouse.portal.invoices.show', $this->invoice->id));
        $response->assertSessionHas('portal_info');
    }

    public function test_pay_invoice_with_no_payment_method_configured_shows_error(): void
    {
        $this->building->update(['payment_method' => null]);

        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->post(route('minihouse.portal.invoices.pay', $this->invoice->id));
        $response->assertRedirect(route('minihouse.portal.invoices.show', $this->invoice->id));
        $response->assertSessionHasErrors('payment');
    }

    public function test_logout_clears_tenant_guard(): void
    {
        Auth::guard('tenant')->login($this->tenantPrimary);
        $this->assertTrue(Auth::guard('tenant')->check());

        $response = $this->post(route('minihouse.portal.logout'));
        $response->assertRedirect(route('minihouse.portal.login'));
        $this->assertFalse(Auth::guard('tenant')->check());

        $dashboard = $this->get(route('minihouse.portal.dashboard'));
        $dashboard->assertRedirect(route('minihouse.portal.login'));
    }

    public function test_tenant_sets_first_password_then_logs_in_with_it(): void
    {
        $this->assertNull($this->tenantPrimary->password);

        Auth::guard('tenant')->login($this->tenantPrimary);

        // Chưa có mật khẩu cũ -> không cần current_password.
        $set = $this->post(route('minihouse.portal.password.update'), [
            'password'              => 'matkhau123',
            'password_confirmation' => 'matkhau123',
        ]);
        $set->assertSessionHas('portal_info');
        $set->assertSessionDoesntHaveErrors();

        $this->tenantPrimary->refresh();
        $this->assertNotNull($this->tenantPrimary->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('matkhau123', $this->tenantPrimary->password));

        Auth::guard('tenant')->logout();
        $this->assertFalse(Auth::guard('tenant')->check());

        $login = $this->post(route('minihouse.portal.login.password'), [
            'phone'    => $this->tenantPrimary->phone,
            'password' => 'matkhau123',
        ]);
        $login->assertRedirect(route('minihouse.portal.dashboard'));
        $this->assertEquals($this->tenantPrimary->id, Auth::guard('tenant')->id());
    }

    public function test_wrong_password_is_rejected_without_leaking_which_part_is_wrong(): void
    {
        $this->tenantPrimary->update(['password' => 'matkhau123']);

        $response = $this->post(route('minihouse.portal.login.password'), [
            'phone'    => $this->tenantPrimary->phone,
            'password' => 'sai-mat-khau',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertFalse(Auth::guard('tenant')->check());
    }

    public function test_password_login_throttles_after_too_many_wrong_attempts(): void
    {
        $this->tenantPrimary->update(['password' => 'matkhau123']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('minihouse.portal.login.password'), [
                'phone'    => $this->tenantPrimary->phone,
                'password' => 'sai-mat-khau',
            ]);
        }

        // Lần thứ 6, dù nhập ĐÚNG mật khẩu vẫn phải bị chặn do rate limit.
        $response = $this->post(route('minihouse.portal.login.password'), [
            'phone'    => $this->tenantPrimary->phone,
            'password' => 'matkhau123',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertFalse(Auth::guard('tenant')->check());
    }

    // 2 khách dùng CHUNG 1 SĐT nhưng TỰ đặt 2 mật khẩu KHÁC nhau — đăng nhập bằng mật khẩu phải vào
    // ĐÚNG hồ sơ khớp mật khẩu, không phải luôn hồ sơ đầu tiên tìm thấy theo SĐT.
    public function test_password_login_picks_the_correct_tenant_among_shared_phone_number(): void
    {
        $this->tenantSharedPhoneA->update(['password' => 'mat-khau-A']);
        $this->tenantSharedPhoneB->update(['password' => 'mat-khau-B']);

        $loginAsB = $this->post(route('minihouse.portal.login.password'), [
            'phone'    => $this->tenantSharedPhoneA->phone,
            'password' => 'mat-khau-B',
        ]);

        $loginAsB->assertRedirect(route('minihouse.portal.dashboard'));
        $this->assertEquals($this->tenantSharedPhoneB->id, Auth::guard('tenant')->id());
    }

    // Không bắt nhập lại mật khẩu cũ khi đổi — đã đăng nhập hợp lệ (OTP hoặc mật khẩu cũ) là đủ xác
    // minh danh tính; bắt nhập lại mật khẩu cũ chỉ tạo lối kẹt vĩnh viễn nếu khách quên (mật khẩu mã
    // hoá một chiều, không ai xem lại được, kể cả admin) trong khi không có luồng "quên mật khẩu"
    // riêng — OTP luôn là đường khôi phục sẵn có.
    public function test_changing_existing_password_does_not_require_the_old_one(): void
    {
        $this->tenantPrimary->update(['password' => 'mat-khau-cu']);
        Auth::guard('tenant')->login($this->tenantPrimary);

        $response = $this->post(route('minihouse.portal.password.update'), [
            'password'              => 'mat-khau-moi',
            'password_confirmation' => 'mat-khau-moi',
        ]);
        $response->assertSessionDoesntHaveErrors();

        $this->tenantPrimary->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('mat-khau-moi', $this->tenantPrimary->password));
    }
}
