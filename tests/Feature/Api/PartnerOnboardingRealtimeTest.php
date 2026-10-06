<?php

namespace Tests\Feature\Api;

use App\Events\PartnerOnboardingStatusChanged;
use App\Models\Partner;
use App\Models\SubscriptionPlan;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\ContractOtpService;
use App\Services\PartnerLegalDocumentService;
use App\Services\PartnerOnboardingRealtimeService;
use App\Services\PartnerOnboardingService;
use App\Services\TermsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// REALTIME + THÔNG BÁO của luồng hợp tác: API trạng thái trả thông tin kênh; mỗi thay đổi hồ sơ phát MỘT tín hiệu Reverb cho đúng hồ sơ đó;
// thông báo admin kèm đối tác + đường dẫn để điều hướng; MiniHouse có thông báo cả ở nhánh thanh toán và khi đăng ký lại.
class PartnerOnboardingRealtimeTest extends TestCase
{
    use DatabaseTransactions;

    private string $token = 'realtime-test-token';

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
        Event::fake([PartnerOnboardingStatusChanged::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(ContractOtpService::class, fn ($mock) => $mock->shouldReceive('verify')->andReturn(true));

        $this->partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Homestay Realtime', 'legal_name' => 'Homestay Realtime', 'phone' => '0970000701',
            'email' => 'realtime@example.test', 'address' => '12 Lê Lợi, Cần Thơ', 'status' => false, 'verification_status' => 'pending',
            'contract_status' => 'draft', 'onboarding_token' => PartnerOnboardingService::hashToken($this->token),
        ]);
        $this->admin = User::create(['fullname' => 'Super Admin Test', 'email' => 'realtime-admin@example.test', 'password' => 'secret-secret']);
        $this->admin->assignRole(config('filament-shield.super_admin.name'));
        $this->adminHeaders = ['Authorization' => 'Bearer ' . $this->admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_status_exposes_the_channel_and_admin_actions_signal_only_that_dossier(): void
    {
        $base = "/api/public/partner-onboarding/{$this->token}";
        $key = PartnerOnboardingRealtimeService::keyFor($this->partner);

        // API trạng thái trả đủ thông tin để FE tự subscribe (Reverb cho web, Socket.IO cho app). Khoá kênh không phải mã hồ sơ.
        $this->getJson($base)->assertOk()
            ->assertJsonPath('data.realtime.key', $key)
            ->assertJsonPath('data.realtime.reverb.channel', "partner-onboarding.{$key}")
            ->assertJsonPath('data.realtime.reverb.event', 'status-changed')
            ->assertJsonPath('data.realtime.socket.subscribe', 'subscribe:partner-onboarding')
            ->assertJsonPath('data.realtime.socket.event', 'partner_onboarding.updated');
        $this->assertSame(40, strlen($key));
        $this->assertStringNotContainsString($this->token, $key);
        Event::assertNotDispatched(PartnerOnboardingStatusChanged::class);

        // Đối tác nộp giấy tờ, ký hợp đồng (hồ sơ tự gửi duyệt).
        $this->post("{$base}/documents", ['type' => 'business_license', 'file' => UploadedFile::fake()->create('dkkd.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->putJson("{$base}/contract-info", ['legal_name' => 'Homestay Realtime', 'address' => '12 Lê Lợi, Cần Thơ', 'email' => 'realtime@example.test',
            'representative_name' => 'Nguyễn Văn An', 'representative_id_number' => '092088001234', 'representative_id_issued_at' => '2021-07-10'])->assertOk();
        $signing = $this->postJson("{$base}/contract")->assertOk()->json('data.contract.signing_token');
        $this->postJson("/api/partner-contracts/{$signing}/confirm", ['otp' => '123456', 'signer_name' => 'Nguyễn Văn An', 'agree' => true])->assertOk();

        // ADMIN duyệt một giấy tờ qua API → đúng MỘT tín hiệu cho request đó, trên kênh của hồ sơ này.
        $before = Event::dispatched(PartnerOnboardingStatusChanged::class)->count();
        $document = $this->partner->legalDocuments()->firstOrFail();
        $this->postJson("/api/admin/partners/{$this->partner->id}/legal-documents/{$document->id}/review", ['status' => 'approved'], $this->adminHeaders)->assertOk();
        $this->assertSame($before + 1, Event::dispatched(PartnerOnboardingStatusChanged::class)->count());
        Event::assertDispatched(PartnerOnboardingStatusChanged::class, fn (PartnerOnboardingStatusChanged $e) => $e->key === $key
            && $e->broadcastOn()[0]->name === "partner-onboarding.{$key}" && $e->broadcastAs() === 'status-changed');

        // Duyệt toàn bộ hồ sơ → lại có tín hiệu; hồ sơ khác không bị phát nhầm.
        $this->postJson("/api/admin/partners/{$this->partner->id}/legal-documents/approve-dossier", [], $this->adminHeaders)->assertOk();
        $this->assertSame($before + 2, Event::dispatched(PartnerOnboardingStatusChanged::class)->count());
        Event::assertNotDispatched(PartnerOnboardingStatusChanged::class, fn (PartnerOnboardingStatusChanged $e) => $e->key !== $key);

        // Thay đổi ngoài request (vd lệnh nền) được gom lại và gửi khi flush; đối tác không có mã hồ sơ thì không phát.
        $other = Partner::create(['partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Tạo tay', 'legal_name' => 'Tạo tay', 'phone' => '0970000702', 'email' => 'realtime-manual@example.test', 'address' => 'x', 'status' => false, 'verification_status' => 'pending', 'contract_status' => 'draft']);
        $other->update(['verification_status' => 'approved']);
        $this->assertSame(0, app(PartnerOnboardingRealtimeService::class)->flush());
        $this->assertNull(PartnerOnboardingRealtimeService::descriptor($other));
    }

    public function test_partner_notifications_carry_navigation_data(): void
    {
        $base = "/api/public/partner-onboarding/{$this->token}";
        $this->post("{$base}/documents", ['type' => 'business_license', 'file' => UploadedFile::fake()->create('dkkd.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->putJson("{$base}/contract-info", ['legal_name' => 'Homestay Realtime', 'address' => '12 Lê Lợi, Cần Thơ', 'email' => 'realtime@example.test',
            'representative_name' => 'Nguyễn Văn An', 'representative_id_number' => '092088001234', 'representative_id_issued_at' => '2021-07-10'])->assertOk();
        $signing = $this->postJson("{$base}/contract")->json('data.contract.signing_token');
        $this->postJson("/api/partner-contracts/{$signing}/confirm", ['otp' => '123456', 'signer_name' => 'Nguyễn Văn An', 'agree' => true])->assertOk();

        // Luồng ký trước: thông báo nói rõ đối tác ĐÃ KÝ; API thông báo trả đối tác + đường dẫn để app/web mở đúng trang.
        $item = collect($this->getJson('/api/admin/notifications?per_page=50', $this->adminHeaders)->assertOk()->json('data'))
            ->firstWhere('partner_id', $this->partner->id);
        $this->assertNotNull($item);
        $this->assertSame('partner_onboarding', $item['type']);
        $this->assertSame('Đối tác đã ký hợp đồng — hồ sơ chờ duyệt', $item['title']);
        $this->assertSame(Partner::TYPE_HOMESTAY, $item['partner_type']);
        $this->assertStringContainsString("/homestay/admin/partners/{$this->partner->id}/edit", $item['url']);
    }

    public function test_minihouse_signups_notify_admins_on_the_payment_branch_and_on_re_registration(): void
    {
        // Tắt dùng thử → mọi đăng ký MiniHouse đi nhánh thanh toán (gói 0đ nên không gọi cổng thanh toán thật).
        config(['partner_flow.minihouse_signup_trial_months' => 0, 'partner_flow.minihouse_contract_enabled' => false]);
        app(TermsService::class)->setRequired(TermsVersion::TYPE_MINIHOUSE, false);
        $plan = SubscriptionPlan::create(['code' => 'mh-rt-test', 'name' => 'Gói test thông báo', 'partner_type' => Partner::TYPE_MINIHOUSE, 'price_vnd' => 0, 'period_months' => 1, 'is_active' => true]);
        $signup = fn () => $this->postJson('/api/public/minihouse-purchase', ['plan_id' => $plan->id, 'periods' => 1, 'full_name' => 'Nguyễn Văn Test',
            'phone' => '0970000703', 'email' => 'realtime-mh@example.test', 'business_name' => 'Nhà trọ Realtime', 'address' => '12 Lê Lợi, Cần Thơ'])->assertCreated();

        $response = $signup();
        $partner = app(PartnerOnboardingService::class)->findByToken($response->json('data.purchase_token'));
        $response->assertJsonPath('data.realtime.reverb.channel', 'partner-onboarding.' . PartnerOnboardingRealtimeService::keyFor($partner));
        $types = fn () => collect($this->getJson('/api/admin/notifications?per_page=50', $this->adminHeaders)->json('data'))->where('partner_id', $partner->id);

        $first = $types()->firstWhere('type', 'minihouse_signup_payment');
        $this->assertNotNull($first, 'Nhánh thanh toán phải có thông báo đăng ký mới.');
        $this->assertSame(Partner::TYPE_MINIHOUSE, $first['partner_type']);
        $this->assertStringContainsString("/minihouse/admin/partners/{$partner->id}/edit", $first['url']);

        // Đăng ký lại bằng hồ sơ dở: báo một lần, bấm lại ngay sau đó không dội thêm thông báo.
        RateLimiter::clear('mh-resignup-notify:' . $partner->id);
        $signup();
        $signup();
        $this->assertSame(1, $types()->where('type', 'minihouse_signup_again')->count());
    }
}
