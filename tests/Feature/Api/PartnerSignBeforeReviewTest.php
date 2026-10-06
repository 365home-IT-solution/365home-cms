<?php

namespace Tests\Feature\Api;

use App\Models\Partner;
use App\Models\User;
use App\Services\ContractOtpService;
use App\Services\PartnerLegalDocumentService;
use App\Services\PartnerOnboardingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// LUỒNG KÝ HỢP ĐỒNG của Homestay đăng ký trên website (config partner_flow.partner_signs_before_review):
//  true  = đối tác ký hợp đồng điều khoản chuẩn TRƯỚC → hồ sơ tự gửi duyệt → 365 Home duyệt rồi ký phía nền tảng.
//  false = luồng cũ: gửi duyệt → duyệt → mới tạo hợp đồng → đối tác ký.
class PartnerSignBeforeReviewTest extends TestCase
{
    use DatabaseTransactions;

    private string $token = 'sign-first-test-token';

    private Partner $partner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'partner_flow.partner_signs_before_review' => true,
            // Giấy tờ bắt buộc rút gọn còn ĐKKD để test tập trung vào thứ tự ký (CCCD/QR đã có test riêng).
            'partner_flow.registration_required_documents.homestay' => ['business_license'],
        ]);
        Storage::fake('local');
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(ContractOtpService::class, fn ($mock) => $mock->shouldReceive('verify')->andReturn(true));

        $this->partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Homestay Ký Trước', 'legal_name' => 'Homestay Ký Trước', 'phone' => '0970000401',
            'email' => 'sign-first@example.test', 'address' => '12 Lê Lợi, Cần Thơ', 'status' => false, 'verification_status' => 'pending',
            'contract_status' => 'draft', 'onboarding_token' => PartnerOnboardingService::hashToken($this->token),
        ]);
        $this->admin = User::create(['fullname' => 'Super Admin Test', 'email' => 'sign-first-admin@example.test', 'password' => 'secret-secret']);
        $this->admin->assignRole(config('filament-shield.super_admin.name'));
    }

    public function test_partner_signs_standard_contract_before_review_then_admin_approves(): void
    {
        $base = "/api/public/partner-onboarding/{$this->token}";
        $this->getJson($base)->assertOk()->assertJsonPath('data.flow.sign_before_review', true);

        // Chưa đủ giấy tờ / thông tin → chưa tạo được hợp đồng; chưa ký → chưa gửi duyệt được.
        $this->postJson("{$base}/contract")->assertStatus(422)->assertJsonValidationErrors('documents');
        $this->uploadLicense();
        $this->postJson("{$base}/contract")->assertStatus(422)->assertJsonValidationErrors('contract_info');
        $this->saveInfo();
        $this->postJson("{$base}/submit")->assertStatus(422)->assertJsonValidationErrors('contract');

        // Tạo hợp đồng điều khoản chuẩn TRƯỚC khi duyệt: hoa hồng mặc định, thời hạn mặc định 12 tháng.
        $signing = $this->postJson("{$base}/contract")->assertOk()
            ->assertJsonPath('data.stage', 'contract_sent')
            ->assertJsonPath('data.steps.documents_approved', false)
            ->json('data.contract.signing_token');
        $this->assertNotEmpty($signing);
        $this->partner->refresh();
        $this->assertSame(today()->addMonths(12)->toDateString(), $this->partner->contract_expires_at->toDateString());
        $this->assertSame(1, $this->partner->contractVersions()->count());
        // Gọi lại không tạo thêm bản mới.
        $this->postJson("{$base}/contract")->assertOk();
        $this->assertSame(1, $this->partner->contractVersions()->count());

        // Đối tác ký được ngay dù hồ sơ chưa duyệt; ký xong hồ sơ TỰ gửi duyệt.
        $this->getJson("/api/partner-contracts/{$signing}")->assertOk()->assertJsonPath('data.can_confirm', true);
        $this->postJson("/api/partner-contracts/{$signing}/confirm", ['otp' => '123456', 'signer_name' => 'Nguyễn Văn An', 'agree' => true])->assertOk();
        $this->getJson($base)->assertJsonPath('data.stage', 'pending_review')
            ->assertJsonPath('data.steps.contract_signed', true)
            ->assertJsonPath('data.steps.submitted', true);
        $this->assertSame('pending_review', $this->partner->legalDocuments()->first()->status);

        // 365 Home duyệt giấy tờ → KHÔNG tạo hợp đồng mới; chỉ còn bước nền tảng ký.
        $documents = app(PartnerLegalDocumentService::class);
        $documents->review($this->partner->legalDocuments()->first(), 'approved', null, $this->admin);
        $this->actingAs($this->admin);
        $documents->approveDossier($this->partner->fresh(), $this->admin);
        $this->assertSame(1, $this->partner->contractVersions()->count());
        $this->getJson($base)->assertJsonPath('data.stage', 'contract_signed')->assertJsonPath('data.steps.documents_approved', true);
        $this->assertTrue($documents->isContractEligible($this->partner->fresh()));

        // Câu chữ mẫu theo luồng ký trước: hiệu lực từ ngày Bên A ký, thời hạn tính từ ngày có hiệu lực (không in ngày hết hạn), giao kết điện tử.
        $content = $this->partner->contractVersions()->first()->content;
        $this->assertStringContainsString('Hợp đồng có hiệu lực kể từ ngày Bên A ký xác nhận.', $content);
        $this->assertStringContainsString('Hợp đồng có thời hạn 12 tháng kể từ ngày Hợp đồng có hiệu lực.', $content);
        $this->assertStringContainsString('Bản điện tử có giá trị như bản gốc.', $content);
        $this->assertStringNotContainsString('đến hết ngày', $content);
        $this->assertStringNotContainsString('02 bản gốc', $content);

        // 365 Home ký trễ 10 ngày → ngày hết hạn tính lại từ NGÀY 365 HOME KÝ, vẫn đủ 12 tháng.
        $this->mock(\App\Services\ContractPdfSigningService::class, fn ($mock) => $mock->shouldReceive('signAndEmbed')->andReturn(['pdf' => '%PDF-1.4 test', 'certificate' => 'test-cert']));
        $this->travel(10)->days();
        app(\App\Services\PartnerContractWorkflowService::class)->platformSign($this->partner->fresh(), $this->admin, '127.0.0.1', 'phpunit');
        $this->partner->refresh();
        $this->assertSame('active', $this->partner->contract_status);
        $this->assertSame(today()->toDateString(), $this->partner->contract_signed_at->toDateString());
        $this->assertSame(today()->addMonths(12)->toDateString(), $this->partner->contract_expires_at->toDateString());
    }

    public function test_editing_contract_info_after_signing_requires_signing_the_new_version(): void
    {
        $base = "/api/public/partner-onboarding/{$this->token}";
        $this->uploadLicense();
        $this->saveInfo();
        $first = $this->postJson("{$base}/contract")->json('data.contract.signing_token');
        $this->postJson("/api/partner-contracts/{$first}/confirm", ['otp' => '123456', 'signer_name' => 'Nguyễn Văn An', 'agree' => true])->assertOk();

        // 365 Home yêu cầu bổ sung → hồ sơ mở lại; đối tác sửa họ tên người đại diện → bản đã ký không còn khớp, phải ký bản mới.
        app(PartnerLegalDocumentService::class)->review($this->partner->legalDocuments()->first(), 'changes_requested', 'Ảnh mờ.', $this->admin);
        $this->getJson($base)->assertJsonPath('data.stage', 'changes_requested');
        $document = $this->partner->legalDocuments()->first();
        $this->deleteJson("{$base}/documents/{$document->id}")->assertOk();
        $this->uploadLicense();
        $this->saveInfo(['representative_name' => 'Trần Thị Bình']);

        $this->assertSame(2, $this->partner->contractVersions()->count());
        $second = $this->getJson($base)->assertJsonPath('data.stage', 'contract_sent')->assertJsonPath('data.steps.contract_signed', false)
            ->json('data.contract.signing_token');
        $this->assertNotSame($first, $second);
        $this->getJson("/api/partner-contracts/{$first}")->assertNotFound();
        $this->postJson("{$base}/submit")->assertStatus(422)->assertJsonValidationErrors('contract');
        $this->postJson("/api/partner-contracts/{$second}/confirm", ['otp' => '123456', 'signer_name' => 'Trần Thị Bình', 'agree' => true])->assertOk();
        $this->getJson($base)->assertJsonPath('data.stage', 'pending_review');
    }

    public function test_flag_off_keeps_review_before_signing(): void
    {
        config(['partner_flow.partner_signs_before_review' => false]);
        $base = "/api/public/partner-onboarding/{$this->token}";
        $this->getJson($base)->assertJsonPath('data.flow.sign_before_review', false);
        $this->uploadLicense();
        $this->saveInfo();

        // Luồng cũ: không tạo hợp đồng trước khi duyệt; gửi duyệt không cần chữ ký.
        $this->postJson("{$base}/contract")->assertStatus(422)->assertJsonValidationErrors('contract');
        $this->postJson("{$base}/submit")->assertOk();
        $this->getJson($base)->assertJsonPath('data.stage', 'pending_review')->assertJsonPath('data.contract', null);
        $this->assertSame(0, $this->partner->contractVersions()->count());

        // Duyệt xong hệ thống mới tạo hợp đồng và gửi cho đối tác ký.
        $documents = app(PartnerLegalDocumentService::class);
        $documents->review($this->partner->legalDocuments()->first(), 'approved', null, $this->admin);
        $this->partner->update(['contract_expires_at' => today()->addYear()]);
        $this->actingAs($this->admin);
        $documents->approveDossier($this->partner->fresh(), $this->admin);
        $this->getJson($base)->assertJsonPath('data.stage', 'contract_sent')->assertJsonPath('data.steps.documents_approved', true);
    }

    private function uploadLicense(): void
    {
        $this->post("/api/public/partner-onboarding/{$this->token}/documents", [
            'type' => 'business_license', 'file' => UploadedFile::fake()->create('dkkd.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    private function saveInfo(array $override = []): void
    {
        $this->putJson("/api/public/partner-onboarding/{$this->token}/contract-info", $override + [
            'legal_name' => 'Homestay Ký Trước', 'address' => '12 Lê Lợi, Cần Thơ', 'email' => 'sign-first@example.test',
            'representative_name' => 'Nguyễn Văn An', 'representative_id_number' => '092088001234', 'representative_id_issued_at' => '2021-07-10',
        ])->assertOk();
    }
}
