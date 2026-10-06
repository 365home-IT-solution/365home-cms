<?php

namespace Tests\Feature\Api;

use App\Models\Partner;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ContractOtpService;
use App\Services\ContractPdfSigningService;
use App\Services\PartnerContractWorkflowService;
use App\Services\PartnerLegalDocumentService;
use App\Services\PartnerOnboardingService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Các API bổ sung cho luồng hợp tác: đối tác xem lại hợp đồng đã ký / tải PDF, sửa giấy tờ tại chỗ, tải lại tệp đã nộp, rút hồ sơ ở luồng ký trước;
// admin xoá đối tác, mở lại hồ sơ tạm dừng, xem lịch sử trạng thái, gia hạn gói MiniHouse.
class PartnerFlowApiCompletenessTest extends TestCase
{
    use DatabaseTransactions;

    private string $token = 'flow-gaps-test-token';

    private string $base;

    private Partner $partner;

    private User $admin;

    private array $adminHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'partner_flow.partner_signs_before_review' => true,
            'partner_flow.registration_required_documents.homestay' => ['business_license'],
        ]);
        Storage::fake('local');
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(ContractOtpService::class, fn ($mock) => $mock->shouldReceive('verify')->andReturn(true));
        $this->mock(ContractPdfSigningService::class, fn ($mock) => $mock->shouldReceive('signAndEmbed')->andReturn(['pdf' => '%PDF-1.4 signed', 'certificate' => 'test-cert']));
        Role::firstOrCreate(['name' => 'partner', 'guard_name' => 'web']);

        $this->base = "/api/public/partner-onboarding/{$this->token}";
        $this->partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Homestay Bổ Sung', 'legal_name' => 'Homestay Bổ Sung', 'phone' => '0970000601',
            'email' => 'flow-gaps@example.test', 'address' => '12 Lê Lợi, Cần Thơ', 'status' => false, 'verification_status' => 'pending',
            'contract_status' => 'draft', 'onboarding_token' => PartnerOnboardingService::hashToken($this->token),
        ]);
        $this->admin = User::create(['fullname' => 'Super Admin Test', 'email' => 'flow-gaps-admin@example.test', 'password' => 'secret-secret']);
        $this->admin->assignRole(config('filament-shield.super_admin.name'));
        $this->adminHeaders = ['Authorization' => 'Bearer ' . $this->admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_partner_can_review_the_contract_after_signing_and_download_the_signed_pdf(): void
    {
        $this->getJson("{$this->base}/contract")->assertNotFound();
        $this->signContract();

        // Ký xong: trạng thái hồ sơ không còn signing_token, nhưng đối tác vẫn xem lại được đúng bản đã ký.
        $this->getJson($this->base)->assertJsonPath('data.contract.signing_token', null);
        $contract = $this->getJson("{$this->base}/contract")->assertOk()
            ->assertJsonPath('data.signing_active', false)
            ->assertJsonPath('data.partner_signed_by', 'Nguyễn Văn An')
            ->assertJsonPath('data.is_fully_signed', false)
            ->assertJsonPath('data.has_signed_pdf', false);
        $this->assertStringContainsString('HỢP ĐỒNG HỢP TÁC KINH DOANH', $contract->json('data.content'));
        $this->assertNotEmpty($contract->json('data.partner_confirmed_at'));
        $this->get("{$this->base}/contract/signed-pdf")->assertNotFound();

        // 365 Home duyệt và ký → có bản PDF đã ký: đối tác tải được bằng mã hồ sơ, và bằng tài khoản của chính mình qua API admin.
        $this->approveAndPlatformSign();
        $this->getJson("{$this->base}/contract")->assertJsonPath('data.is_fully_signed', true)->assertJsonPath('data.has_signed_pdf', true)
            ->assertJsonPath('data.contract_status', 'active');
        $this->get("{$this->base}/contract/signed-pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        // Trạng thái hồ sơ báo có PDF để trang đăng ký hiện nút tải.
        $this->getJson($this->base)->assertJsonPath('data.contract.has_signed_pdf', true);

        $owner = $this->partner->fresh()->users()->firstOrFail();
        $this->get("/api/admin/partners/{$this->partner->id}/contract/signed-pdf", $this->headersFor($owner))->assertOk();

        // Tài khoản của đối tác khác không tải được.
        $other = Partner::create(['partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Khác', 'legal_name' => 'Khác', 'phone' => '0970000602', 'email' => 'flow-gaps-other@example.test', 'address' => 'x', 'status' => true, 'verification_status' => 'approved', 'contract_status' => 'draft']);
        $stranger = User::create(['fullname' => 'Người lạ', 'email' => 'flow-gaps-stranger@example.test', 'password' => 'secret-secret', 'partner_id' => $other->id]);
        $stranger->assignRole('partner');
        $this->get("/api/admin/partners/{$this->partner->id}/contract/signed-pdf", $this->headersFor($stranger))->assertForbidden();
    }

    public function test_partner_can_edit_a_document_in_place_download_it_and_withdraw_after_signing(): void
    {
        $id = $this->uploadLicense()->json('data.id');

        // Sửa tại chỗ: đổi ô thông tin, giữ tệp cũ; rồi thay tệp.
        $this->post("{$this->base}/documents/{$id}", ['dkkd_document_number' => '1801709047', 'dkkd_issuer' => 'Phòng ĐKKD'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.document_number', '1801709047')->assertJsonPath('data.file_name', 'dkkd.pdf')->assertJsonPath('data.status', 'draft');
        $this->post("{$this->base}/documents/{$id}", ['file' => UploadedFile::fake()->create('dkkd-moi.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.file_name', 'dkkd-moi.pdf')->assertJsonPath('data.document_number', '1801709047');
        $this->assertSame(1, $this->partner->legalDocuments()->count());

        // Tải lại tệp đã nộp; giấy tờ của hồ sơ khác / mặt sau không có → 404.
        $this->get("{$this->base}/documents/{$id}/download")->assertOk();
        $this->get("{$this->base}/documents/{$id}/download?side=back")->assertNotFound();

        // Luồng ký trước: ký xong hồ sơ tự gửi duyệt → không sửa được; RÚT hồ sơ được dù đã có hợp đồng, chữ ký giữ nguyên.
        $this->signContract(uploaded: true);
        $this->getJson($this->base)->assertJsonPath('data.stage', 'pending_review');
        $this->post("{$this->base}/documents/{$id}", ['dkkd_issuer' => 'Sửa lúc chờ duyệt'], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("{$this->base}/withdraw")->assertOk()->assertJsonPath('data.stage', 'ready_to_submit')->assertJsonPath('data.steps.contract_signed', true);
        $this->post("{$this->base}/documents/{$id}", ['dkkd_issuer' => 'Sở Tài chính'], ['Accept' => 'application/json'])->assertOk();
        $this->postJson("{$this->base}/submit")->assertOk()->assertJsonPath('data.stage', 'pending_review');
    }

    public function test_admin_can_read_status_logs_reactivate_and_delete_partners(): void
    {
        $url = "/api/admin/partners/{$this->partner->id}";
        $this->signContract();
        $this->approveAndPlatformSign();

        // Tạm dừng rồi mở lại: về đúng trạng thái trước khi tạm dừng.
        $this->postJson("{$url}/reactivate", [], $this->adminHeaders)->assertStatus(422);
        $this->postJson("{$url}/suspend", ['note' => 'Kiểm tra lại'], $this->adminHeaders)->assertOk()->assertJsonPath('data.verification_status', 'suspended');
        $this->postJson("{$url}/reactivate", ['note' => 'Đã kiểm tra xong'], $this->adminHeaders)->assertOk()->assertJsonPath('data.verification_status', 'approved');
        $this->assertTrue((bool) $this->partner->fresh()->status);

        // Lịch sử trạng thái: mới nhất trước, có người thực hiện.
        $this->getJson("{$url}/status-logs", $this->adminHeaders)->assertOk()
            ->assertJsonPath('data.0.from_status', 'suspended')->assertJsonPath('data.0.to_status', 'approved')
            ->assertJsonPath('data.0.note', 'Đã kiểm tra xong')->assertJsonPath('data.0.changed_by.name', 'Super Admin Test');

        // Xoá: hợp đồng đang hiệu lực thì chặn; chấm dứt rồi mới xoá được. Tài khoản không phải Super Admin bị từ chối.
        $this->deleteJson($url, [], $this->adminHeaders)->assertStatus(422);
        // Cùng một quy tắc cho API và trang quản trị (Partner::deletionBlockedReason), kèm chốt chặn ở model.
        $this->assertNotNull($this->partner->fresh()->deletionBlockedReason());
        try {
            $this->partner->fresh()->delete();
            $this->fail('Không được xoá đối tác có hợp đồng đang hiệu lực.');
        } catch (\DomainException) {
            $this->assertNull($this->partner->fresh()->deleted_at);
        }
        $owner = $this->partner->fresh()->users()->firstOrFail();
        $this->deleteJson($url, [], $this->headersFor($owner))->assertForbidden();
        $this->getJson("{$url}/status-logs", $this->headersFor($owner))->assertForbidden();
        $this->postJson("{$url}/contract/terminate", [], $this->headersFor($this->admin))->assertOk();
        $this->deleteJson($url, [], $this->adminHeaders)->assertOk();
        $this->assertSoftDeleted('partners', ['id' => $this->partner->id]);
        $this->getJson($url, $this->adminHeaders)->assertNotFound();
    }

    public function test_admin_can_extend_a_minihouse_subscription(): void
    {
        $plan = SubscriptionPlan::create(['code' => 'mh-extend-test', 'name' => 'Gói test gia hạn', 'partner_type' => Partner::TYPE_MINIHOUSE, 'price_vnd' => 0, 'period_months' => 1, 'is_active' => true]);
        $minihouse = Partner::create(['partner_type' => Partner::TYPE_MINIHOUSE, 'name' => 'Nhà trọ Gia Hạn', 'legal_name' => 'Nhà trọ Gia Hạn', 'phone' => '0970000603', 'email' => 'flow-gaps-mh@example.test', 'address' => 'x', 'status' => true, 'verification_status' => 'approved', 'contract_status' => 'draft']);
        $url = "/api/admin/minihouse/partners/{$minihouse->id}/subscription/extend";

        app(SubscriptionService::class)->assignPlan($minihouse, $plan, now()->addDays(10), true);
        $this->postJson($url, ['months' => 0], $this->adminHeaders)->assertStatus(422)->assertJsonValidationErrors('months');

        // Còn hạn 10 ngày → cộng nối tiếp 2 tháng; gói dùng thử chuyển thành gói thường.
        $this->postJson($url, ['months' => 2], $this->adminHeaders)->assertOk()->assertJsonPath('data.subscription.is_trial', false);
        $this->assertSame(now()->addDays(10)->addMonthsNoOverflow(2)->toDateString(), $minihouse->subscription()->first()->expires_at->toDateString());

        // Endpoint chỉ dành cho MiniHouse.
        $this->postJson("/api/admin/minihouse/partners/{$this->partner->id}/subscription/extend", ['months' => 1], $this->adminHeaders)->assertNotFound();
    }

    // Trang quản trị MiniHouse: nút "Tạm dừng hồ sơ" / "Mở lại hồ sơ" dùng chung quy tắc với API.
    public function test_minihouse_admin_page_can_suspend_and_reactivate(): void
    {
        $minihouse = Partner::create(['partner_type' => Partner::TYPE_MINIHOUSE, 'name' => 'Nhà trọ Tạm Dừng', 'legal_name' => 'Nhà trọ Tạm Dừng', 'phone' => '0970000604', 'email' => 'flow-gaps-mh2@example.test', 'address' => 'x', 'status' => true, 'verification_status' => 'approved', 'contract_status' => 'draft']);
        $this->actingAs($this->admin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('minihouse-admin'));
        $page = \Modules\Minihouse\App\Filament\Resources\PartnerResource\Pages\EditPartner::class;

        \Livewire\Livewire::test($page, ['record' => $minihouse->id])
            ->assertActionVisible('suspend')->assertActionHidden('reactivate')
            ->callAction('suspend', ['note' => 'Tạm khoá để kiểm tra']);
        $this->assertSame('suspended', $minihouse->fresh()->verification_status);
        $this->assertFalse((bool) $minihouse->fresh()->status);

        \Livewire\Livewire::test($page, ['record' => $minihouse->id])
            ->assertActionHidden('suspend')->assertActionVisible('reactivate')
            ->callAction('reactivate');
        $this->assertSame('approved', $minihouse->fresh()->verification_status);
        $this->assertTrue((bool) $minihouse->fresh()->status);
    }

    /** Header đăng nhập cho một tài khoản. Trong cùng một test, guard nhớ người dùng của request trước nên phải xoá trước khi đổi tài khoản. */
    private function headersFor(User $user): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function uploadLicense()
    {
        return $this->post("{$this->base}/documents", [
            'type' => 'business_license', 'file' => UploadedFile::fake()->create('dkkd.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    private function signContract(bool $uploaded = false): void
    {
        if (! $uploaded) {
            $this->uploadLicense();
        }
        $this->putJson("{$this->base}/contract-info", [
            'legal_name' => 'Homestay Bổ Sung', 'address' => '12 Lê Lợi, Cần Thơ', 'email' => 'flow-gaps@example.test',
            'representative_name' => 'Nguyễn Văn An', 'representative_id_number' => '092088001234', 'representative_id_issued_at' => '2021-07-10',
        ])->assertOk();
        $signing = $this->postJson("{$this->base}/contract")->assertOk()->json('data.contract.signing_token');
        $this->postJson("/api/partner-contracts/{$signing}/confirm", ['otp' => '123456', 'signer_name' => 'Nguyễn Văn An', 'agree' => true])->assertOk();
    }

    private function approveAndPlatformSign(): void
    {
        $documents = app(PartnerLegalDocumentService::class);
        foreach ($this->partner->legalDocuments()->get() as $document) {
            $documents->review($document, 'approved', null, $this->admin);
        }
        $this->actingAs($this->admin);
        $documents->approveDossier($this->partner->fresh(), $this->admin);
        app(PartnerContractWorkflowService::class)->platformSign($this->partner->fresh(), $this->admin, '127.0.0.1', 'phpunit');
        app('auth')->forgetGuards();
    }
}
