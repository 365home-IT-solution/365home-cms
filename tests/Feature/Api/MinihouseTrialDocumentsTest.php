<?php

namespace Tests\Feature\Api;

use App\Models\Partner;
use App\Models\PartnerLegalDocument;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\PartnerLegalDocumentService;
use App\Services\PartnerOnboardingService;
use App\Services\TermsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// MiniHouse ĐĂNG KÝ DÙNG THỬ có bước giấy tờ (MINIHOUSE_TRIAL_DOCUMENTS_REQUIRED): đăng ký → nộp giấy tờ bắt buộc cấp đối tác → gửi duyệt
// → Super Admin duyệt → tặng dùng thử + cấp tài khoản. Nhánh thanh toán gói ngay KHÔNG đổi.
// Giấy tờ bắt buộc bật/tắt ở config partner_flow.registration_required_documents: mặc định MiniHouse ĐKKD + CCCD, Homestay ĐKKD + CCCD + ANTT.
// CCCD bắt buộc đọc được mã QR trên ảnh (bộ giải mã QR được giả lập trong test).
// Các test luồng cũ bật lại đủ 3 giấy tờ (ĐKKD + ANTT + PCCC) trong setUp.
// Gói test có giá 0đ nên nhánh thanh toán chỉ ghi nhận yêu cầu, KHÔNG gọi PayOS thật.
class MinihouseTrialDocumentsTest extends TestCase
{
    use DatabaseTransactions;

    private SubscriptionPlan $plan;

    private User $admin;

    // Bộ 3 giấy tờ của luồng cũ (trước khi có cấu hình theo loại đối tác) — các test luồng cũ bật lại đúng bộ này.
    private const LEGACY_REQUIRED = ['business_license', 'security_order', 'fire_safety'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Kết quả đọc QR CCCD được nhớ tạm theo nội dung tệp — dùng cache trong bộ nhớ để không dính sang lần chạy khác.
            'cache.default' => 'array',
            'partner_flow.minihouse_trial_documents_required' => true,
            'partner_flow.minihouse_contract_enabled' => false,
            'partner_flow.minihouse_signup_trial_months' => 1,
            'partner_flow.registration_required_documents.minihouse' => self::LEGACY_REQUIRED,
        ]);
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        app(TermsService::class)->setRequired(TermsVersion::TYPE_MINIHOUSE, false);
        Role::firstOrCreate(['name' => 'Quản lý MiniHouse', 'guard_name' => 'web']);

        $this->plan = SubscriptionPlan::create([
            'code' => 'mh-docs-test', 'name' => 'Gói test giấy tờ', 'partner_type' => Partner::TYPE_MINIHOUSE,
            'price_vnd' => 0, 'period_months' => 1, 'is_active' => true,
        ]);
        $this->admin = User::create(['fullname' => 'Super Admin Test', 'email' => 'mh-docs-admin@example.test', 'password' => 'secret-secret']);
        $this->admin->assignRole(config('filament-shield.super_admin.name'));
    }

    public function test_trial_signup_requires_three_approved_documents_before_account_is_issued(): void
    {
        [$token, $partner] = $this->register('0970000101', 'mh-docs-trial@example.test');

        // Mới đăng ký: chưa có đơn thanh toán, chưa có tài khoản, phải nộp 3 giấy tờ.
        $this->getJson("/api/public/minihouse-purchase/{$token}")
            ->assertOk()
            ->assertJsonPath('data.stage', 'documents')
            ->assertJsonPath('data.documents_required', true)
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.account.created', false)
            ->assertJsonCount(3, 'data.dossier.required_documents');

        // Chưa đủ giấy tờ bắt buộc thì không gửi duyệt được.
        $this->upload($token, 'business_license');
        $this->postJson("/api/public/partner-onboarding/{$token}/submit")->assertStatus(422)->assertJsonValidationErrors('documents');

        // PCCC + ANTT nộp ở CẤP ĐỐI TÁC (không cần toà nhà) như Homestay.
        $this->upload($token, 'security_order');
        $this->upload($token, 'fire_safety');
        $this->getJson("/api/public/minihouse-purchase/{$token}")->assertJsonPath('data.dossier.can_submit', true);

        $this->postJson("/api/public/partner-onboarding/{$token}/submit")->assertOk();
        $this->getJson("/api/public/minihouse-purchase/{$token}")->assertJsonPath('data.stage', 'pending_review');

        // Giấy tờ chưa được duyệt → không duyệt đăng ký / tặng dùng thử được.
        try {
            app(PartnerOnboardingService::class)->approveSignup($partner->fresh(), $this->admin);
            $this->fail('Duyệt đăng ký phải bị chặn khi giấy tờ chưa được duyệt.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('legal_documents', $e->errors());
        }
        $this->assertFalse($partner->users()->exists());

        $this->approveAllDocuments($partner);
        app(PartnerLegalDocumentService::class)->approveDossier($partner->fresh(), $this->admin);

        $partner->refresh();
        $this->assertSame('approved', $partner->verification_status);
        $this->assertTrue((bool) $partner->status);
        $this->assertTrue($partner->users()->exists());
        $this->assertTrue((bool) $partner->subscription?->is_trial);
        $this->getJson("/api/public/minihouse-purchase/{$token}")
            ->assertJsonPath('data.stage', 'trial')
            ->assertJsonPath('data.account.created', true)
            ->assertJsonPath('data.dossier', null);
    }

    public function test_minihouse_needs_business_license_and_qr_readable_citizen_id_by_default(): void
    {
        config(['partner_flow.registration_required_documents' => (require config_path('partner_flow.php'))['registration_required_documents']]);
        $this->assertSame(['business_license', 'citizen_id'], PartnerLegalDocument::registrationRequiredFor(Partner::TYPE_MINIHOUSE));
        $this->assertSame(['business_license', 'citizen_id', 'security_order'], PartnerLegalDocument::registrationRequiredFor(Partner::TYPE_HOMESTAY));

        [$token, $partner] = $this->register('0970000106', 'mh-docs-dkkd@example.test');
        $this->getJson("/api/public/minihouse-purchase/{$token}")
            ->assertJsonPath('data.stage', 'documents')
            ->assertJsonCount(2, 'data.dossier.required_documents')
            ->assertJsonPath('data.dossier.required_documents.0.type', 'business_license')
            ->assertJsonPath('data.dossier.required_documents.1.type', 'citizen_id');
        $this->getJson('/api/public/legal-document-types')
            ->assertJsonPath('data.required.minihouse', ['business_license', 'citizen_id'])
            ->assertJsonPath('data.required.homestay', ['business_license', 'citizen_id', 'security_order']);

        // Thiếu CCCD thì chưa gửi duyệt được.
        $this->upload($token, 'business_license');
        $this->getJson("/api/public/minihouse-purchase/{$token}")->assertJsonPath('data.dossier.can_submit', false);

        // CCCD không đọc được mã QR → từ chối cả lúc quét lẫn lúc nộp, không lưu gì. Tệp PDF cũng không được nhận.
        $this->fakeQr(null);
        $this->postCccd("/api/public/partner-onboarding/{$token}/documents/scan")->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postCccd("/api/public/partner-onboarding/{$token}/documents")->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($token, 'citizen_id', 422);
        $this->assertSame(0, $partner->legalDocuments()->where('type', 'citizen_id')->count());

        // Đọc được QR → quét trả đủ ô; lúc nộp server lấy dữ liệu TỪ QR, bỏ qua số CCCD client tự gửi.
        $this->fakeQr(['cccd' => '092088001234', 'old_id' => '', 'full_name' => 'NGUYỄN VĂN AN', 'dob' => '05/03/1988', 'gender' => 'Nam',
            'address' => '12 Lê Lợi, Ninh Kiều, Cần Thơ', 'issued_date' => '10/07/2021', 'source' => 'qr']);
        $this->postCccd("/api/public/partner-onboarding/{$token}/documents/scan")->assertOk()
            ->assertJsonPath('data.qr', true)
            ->assertJsonPath('data.fields.cccd_document_number', '092088001234')
            ->assertJsonPath('data.fields.cccd_dob', '1988-03-05');
        $this->postCccd("/api/public/partner-onboarding/{$token}/documents", ['cccd_document_number' => '111111111111', 'cccd_issuer' => 'Cục CS QLHC về TTXH'])
            ->assertCreated()
            ->assertJsonPath('data.document_number', '092088001234');
        $cccd = $partner->legalDocuments()->where('type', 'citizen_id')->firstOrFail();
        $this->assertTrue((bool) $cccd->is_required);
        $this->assertSame('NGUYỄN VĂN AN', $cccd->cccd_full_name);
        $this->assertSame('2021-07-10', $cccd->cccd_issued_at->toDateString());
        $this->assertSame('Cục CS QLHC về TTXH', $cccd->cccd_issuer);

        // Đủ ĐKKD + CCCD là gửi duyệt được; PCCC vẫn nộp được nhưng không bắt buộc.
        $this->getJson("/api/public/minihouse-purchase/{$token}")->assertJsonPath('data.dossier.can_submit', true);
        $this->upload($token, 'fire_safety');
        $this->assertFalse((bool) $partner->legalDocuments()->where('type', 'fire_safety')->firstOrFail()->is_required);
        $this->postJson("/api/public/partner-onboarding/{$token}/submit")->assertOk();

        // Duyệt ĐKKD + CCCD là đủ điều kiện duyệt hồ sơ → tặng dùng thử + cấp tài khoản (PCCC tuỳ chọn chưa duyệt không chặn).
        foreach ($partner->legalDocuments()->whereIn('type', ['business_license', 'citizen_id'])->get() as $document) {
            app(PartnerLegalDocumentService::class)->review($document, 'approved', null, $this->admin);
        }
        app(PartnerLegalDocumentService::class)->approveDossier($partner->fresh(), $this->admin);
        $this->assertTrue($partner->fresh()->users()->exists());
    }

    public function test_changes_requested_reopens_the_dossier_for_editing(): void
    {
        [$token, $partner] = $this->register('0970000103', 'mh-docs-changes@example.test');
        foreach (self::LEGACY_REQUIRED as $type) {
            $this->upload($token, $type);
        }
        $this->postJson("/api/public/partner-onboarding/{$token}/submit")->assertOk();

        // Đang chờ duyệt: không sửa được.
        $this->upload($token, 'other', 422);

        $fire = $partner->legalDocuments()->where('type', 'fire_safety')->firstOrFail();
        app(PartnerLegalDocumentService::class)->review($fire, 'changes_requested', 'Ảnh mờ, vui lòng chụp lại.', $this->admin);

        $this->getJson("/api/public/minihouse-purchase/{$token}")->assertJsonPath('data.stage', 'changes_requested');
        $this->deleteJson("/api/public/partner-onboarding/{$token}/documents/{$fire->id}")->assertOk();
        $this->upload($token, 'fire_safety');
        $this->postJson("/api/public/partner-onboarding/{$token}/submit")->assertOk();
        $this->getJson("/api/public/minihouse-purchase/{$token}")->assertJsonPath('data.stage', 'pending_review');
    }

    public function test_paid_signup_is_unchanged_and_needs_no_documents(): void
    {
        // Tắt dùng thử → mọi đăng ký đi nhánh thanh toán: tạo đơn ngay như cũ, không có bước giấy tờ.
        config(['partner_flow.minihouse_signup_trial_months' => 0]);
        [$token, $partner] = $this->register('0970000102', 'mh-docs-pay@example.test');

        $this->assertSame(1, SubscriptionPayment::query()->where('partner_id', $partner->id)->count());
        $this->getJson("/api/public/minihouse-purchase/{$token}")
            ->assertJsonPath('data.stage', 'pending_payment')
            ->assertJsonPath('data.documents_required', false)
            ->assertJsonPath('data.dossier', null);
    }

    // Hồ sơ đăng ký bị xoá khi CHƯA từng có gói không làm mất quyền dùng thử; đã từng dùng thử rồi xoá thì đăng ký lại phải thanh toán.
    public function test_a_deleted_signup_that_never_had_a_plan_does_not_burn_the_trial(): void
    {
        config(['partner_flow.minihouse_trial_documents_required' => false]);

        [, $deleted] = $this->register('0970000107', 'mh-docs-deleted@example.test');
        $deleted->delete();
        [$token] = $this->register('0970000107', 'mh-docs-deleted@example.test');
        $this->getJson("/api/public/minihouse-purchase/{$token}")->assertJsonPath('data.stage', 'pending_approval')->assertJsonPath('data.payment', null);

        [, $used] = $this->register('0970000108', 'mh-docs-used@example.test');
        app(PartnerOnboardingService::class)->approveSignup($used->fresh(), $this->admin);
        $used->users()->delete();
        $used->fresh()->delete();
        [$again] = $this->register('0970000108', 'mh-docs-used@example.test');
        $this->getJson("/api/public/minihouse-purchase/{$again}")->assertJsonPath('data.stage', 'pending_payment');
    }

    public function test_flag_off_keeps_trial_approval_without_documents(): void
    {
        config(['partner_flow.minihouse_trial_documents_required' => false]);
        [$token, $partner] = $this->register('0970000104', 'mh-docs-off@example.test');

        $this->getJson("/api/public/minihouse-purchase/{$token}")
            ->assertJsonPath('data.stage', 'pending_approval')
            ->assertJsonPath('data.documents_required', false)
            ->assertJsonPath('data.dossier', null);

        $result = app(PartnerOnboardingService::class)->approveSignup($partner->fresh(), $this->admin);
        $this->assertTrue($result['created']);
    }

    public function test_documents_of_an_active_minihouse_account_never_lock_its_login(): void
    {
        [$token, $partner] = $this->register('0970000105', 'mh-docs-active@example.test');
        foreach (self::LEGACY_REQUIRED as $type) {
            $this->upload($token, $type);
        }
        $this->postJson("/api/public/partner-onboarding/{$token}/submit")->assertOk();
        $this->approveAllDocuments($partner);
        app(PartnerLegalDocumentService::class)->approveDossier($partner->fresh(), $this->admin);
        $partner->refresh();
        $this->assertTrue($partner->users()->exists());

        // Đã có tài khoản: bổ sung giấy tờ mới rồi gửi duyệt / bị yêu cầu bổ sung KHÔNG đưa đối tác về "pending" (sẽ khoá đăng nhập).
        $extra = $partner->legalDocuments()->create(['type' => 'tax_registration', 'status' => 'draft']);
        $extra->addMedia(UploadedFile::fake()->create('thue.pdf', 50, 'application/pdf'))->toMediaCollection('file');
        app(PartnerLegalDocumentService::class)->submit($partner);
        $this->assertSame('approved', $partner->fresh()->verification_status);

        app(PartnerLegalDocumentService::class)->review($extra->fresh(), 'changes_requested', 'Thiếu trang 2.', $this->admin);
        $this->assertSame('approved', $partner->fresh()->verification_status);
    }

    // Môi trường CHƯA có vai trò "Quản lý MiniHouse" (seeder chưa chạy): duyệt vẫn tạo được tài khoản ĐĂNG NHẬP ĐƯỢC và hiện trong danh sách tài khoản MiniHouse.
    // Tài khoản chủ đối tác thiếu vai trò (tạo tay) được gán bổ sung khi bấm "Gửi lại tài khoản đăng nhập".
    public function test_owner_account_can_log_in_even_when_the_manager_role_was_never_seeded(): void
    {
        config(['partner_flow.minihouse_trial_documents_required' => false]);
        Role::query()->where('name', 'Quản lý MiniHouse')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $panel = \Filament\Facades\Filament::getPanel('minihouse-admin');
        $onboarding = app(PartnerOnboardingService::class);

        [, $partner] = $this->register('0970000109', 'mh-docs-role@example.test');
        $result = $onboarding->approveSignup($partner->fresh(), $this->admin);
        $this->assertTrue($result['created']);
        $user = $partner->users()->firstOrFail();
        $this->assertTrue($user->hasRole('Quản lý MiniHouse'));
        $this->assertTrue($user->canAccessPanel($panel));
        $this->assertTrue(\Modules\Minihouse\App\Support\MinihousePermissions::scopeToMinihouseUsers(User::query())->whereKey($user->id)->exists());

        // Tài khoản chủ đối tác bị mất vai trò (hoặc được tạo tay không kèm vai trò) → không đăng nhập được; gửi lại tài khoản thì gán lại.
        $user->roles()->detach();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($user->fresh()->canAccessPanel($panel));
        $onboarding->resendCredentials($partner->fresh());
        $this->assertTrue($user->fresh()->canAccessPanel($panel));
    }

    /** @return array{0: string, 1: Partner} */
    private function register(string $phone, string $email): array
    {
        $response = $this->postJson('/api/public/minihouse-purchase', [
            'plan_id' => $this->plan->id, 'periods' => 1,
            'full_name' => 'Nguyễn Văn Test', 'phone' => $phone, 'email' => $email,
            'business_name' => 'Nhà trọ Test ' . $phone, 'address' => '12 Lê Lợi, Cần Thơ',
        ])->assertCreated();

        $token = $response->json('data.purchase_token');

        return [$token, app(PartnerOnboardingService::class)->findByToken($token)];
    }

    private function upload(string $token, string $type, int $status = 201): void
    {
        $this->post("/api/public/partner-onboarding/{$token}/documents", [
            'type' => $type,
            'file' => UploadedFile::fake()->create("{$type}.pdf", 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus($status);
    }

    /** Giả lập bộ giải mã QR CCCD: trả dữ liệu QR cho trước, hoặc null = không đọc được mã QR. */
    private function fakeQr(?array $data): void
    {
        $this->mock(\Modules\Payment\App\Services\CccdScannerService::class, fn ($mock) => $mock->shouldReceive('scanQrImage')->andReturn($data));
    }

    private function postCccd(string $url, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post($url, ['type' => 'citizen_id', 'file' => UploadedFile::fake()->image('cccd.jpg', 800, 500)] + $extra, ['Accept' => 'application/json']);
    }

    private function approveAllDocuments(Partner $partner): void
    {
        foreach ($partner->legalDocuments()->get() as $document) {
            app(PartnerLegalDocumentService::class)->review($document, 'approved', null, $this->admin);
        }
    }
}
