<?php

namespace Tests\Feature\Api;

use App\Models\Partner;
use App\Models\PartnerEscrowDeposit;
use App\Models\PartnerEscrowEntry;
use App\Models\User;
use App\Services\AdminNotificationRealtimeService;
use App\Services\EscrowPayosService;
use App\Services\EscrowService;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Modules\Product\App\Models\Product;
use Tests\TestCase;

// KÝ QUỸ ĐỐI TÁC: số dư chỉ đổi qua sổ bút toán; đề xuất trừ đi qua đồng ý/khiếu nại/chốt; số dư thấp thì cảnh báo rồi tạm ngưng bán.
class PartnerEscrowTest extends TestCase
{
    use DatabaseTransactions;

    private const MIN = 5_000_000;

    private Partner $partner;

    private array $adminHeaders;

    private array $ownerHeaders;

    private array $staffHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        // Test có tua thời gian qua hạn nạp: token đăng nhập không được hết hạn theo.
        config(['sanctum.expiration' => null]);
        // Không tạo link PayOS thật: yêu cầu nạp vẫn có mã giao dịch để đối chiếu qua nội dung chuyển khoản.
        config(['payos.client_id' => null, 'payos.api_key' => null, 'payos.checksum_key' => null]);
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUsers'));
        $this->mock(AdminNotificationRealtimeService::class, fn ($mock) => $mock->shouldReceive('broadcastNew'));

        $this->partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Homestay Ký Quỹ', 'legal_name' => 'Homestay Ký Quỹ', 'phone' => '0970000811',
            'status' => true, 'verification_status' => 'approved', 'contract_status' => 'active',
        ]);

        $admin = User::create(['fullname' => 'Super Admin Test', 'email' => 'escrow-admin@example.test', 'password' => 'secret-secret']);
        $admin->assignRole(config('filament-shield.super_admin.name'));
        $owner = User::create(['fullname' => 'Chủ đối tác', 'email' => 'escrow-owner@example.test', 'password' => 'secret-secret', 'partner_id' => $this->partner->id]);
        $owner->assignRole(config('permission.models.role')::firstOrCreate(['name' => 'partner', 'guard_name' => 'web']));
        $staff = User::create(['fullname' => 'Nhân viên', 'email' => 'escrow-staff@example.test', 'password' => 'secret-secret', 'partner_id' => $this->partner->id]);

        $headers = fn (User $user) => ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $this->adminHeaders = $headers($admin);
        $this->ownerHeaders = $headers($owner);
        $this->staffHeaders = $headers($staff);
    }

    // Mỗi request trong cùng một test dùng token riêng: bỏ user đã nhớ ở guard để request sau không dùng lại danh tính request trước.
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/partners/{$this->partner->id}/escrow{$suffix}";
    }

    private function evidence(): UploadedFile
    {
        return UploadedFile::fake()->create('chung-tu.pdf', 50, 'application/pdf');
    }

    /** Đặt mức tối thiểu có hiệu lực ngay và nạp tay cho đủ. */
    private function fund(int $amount = self::MIN): void
    {
        $this->putJson($this->url('/settings'), ['min_amount' => self::MIN, 'enforce_from' => now()->subMinute()->toIso8601String()], $this->adminHeaders)->assertOk();
        $this->post($this->url('/entries'), ['type' => 'deposit', 'amount' => $amount, 'reason' => 'Chuyển khoản ngoài', 'evidence' => [$this->evidence()]], $this->adminHeaders)->assertCreated();
    }

    private function propose(array $override = []): int
    {
        return $this->postJson($this->url('/deductions'), $override + ['type' => 'deduct_penalty', 'amount' => 1_000_000, 'reason' => 'Huỷ đơn đã xác nhận', 'reference' => 'BB-01'], $this->adminHeaders)
            ->assertCreated()->json('data.deduction.id');
    }

    private function escrow(): array
    {
        return $this->getJson($this->url(), $this->ownerHeaders)->assertOk()->json('data');
    }

    public function test_escrow_is_opt_in_and_the_minimum_gates_sales(): void
    {
        // Chưa đặt mức: không áp dụng, không khoá.
        $this->assertSame('not_required', $this->escrow()['status']);
        $this->assertNull(EscrowService::salesBlockResponse($this->partner->id));

        // Chỉ Super Admin đặt mức; mặc định đối tác được hạn nạp ban đầu (chưa khoá).
        $this->putJson($this->url('/settings'), ['min_amount' => self::MIN], $this->ownerHeaders)->assertForbidden();
        $this->putJson($this->url('/settings'), ['min_amount' => self::MIN], $this->adminHeaders)->assertOk()->assertJsonPath('data.status', 'grace');
        $this->assertFalse($this->escrow()['sales_suspended']);

        // Hết hạn nạp ban đầu mà chưa nạp → tạm ngưng bán: chặn đặt phòng (423) và ẩn phòng khỏi danh sách công khai.
        $this->travel((int) config('escrow.initial_grace_days') + 1)->days();
        $this->artisan('escrow:process')->assertSuccessful();
        $state = $this->escrow();
        $this->assertSame('suspended', $state['status']);
        $this->assertSame(self::MIN, $state['shortfall']);
        $blocked = EscrowService::salesBlockResponse($this->partner->id);
        $this->assertSame(423, $blocked->status());
        $this->assertSame('PARTNER_ESCROW_LOW', $blocked->getData(true)['code']);
        $this->assertStringContainsString('partner_id', Product::query()->activeBranch()->toSql());

        // Nạp tay phải có chứng từ; nạp đủ thì mở bán lại.
        $this->postJson($this->url('/entries'), ['type' => 'deposit', 'amount' => self::MIN, 'reason' => 'Chuyển khoản ngoài'], $this->adminHeaders)->assertStatus(422);
        $this->post($this->url('/entries'), ['type' => 'deposit', 'amount' => self::MIN, 'reason' => 'Chuyển khoản ngoài', 'evidence' => [$this->evidence()]], $this->adminHeaders)
            ->assertCreated()->assertJsonPath('data.entry.balance_after', self::MIN)->assertJsonPath('data.escrow.status', 'ok');
        $this->assertNull(EscrowService::salesBlockResponse($this->partner->id));
        $this->assertNotContains($this->partner->id, EscrowService::suspendedPartnerIds());
    }

    public function test_low_balance_warns_then_suspends(): void
    {
        $this->fund();

        // Còn 60% mức tối thiểu: cảnh báo + hạn nạp bù, chưa khoá.
        $this->postJson($this->url("/deductions/{$this->propose(['amount' => 2_000_000])}/accept"), [], $this->ownerHeaders)->assertOk();
        $state = $this->escrow();
        $this->assertSame('low', $state['status']);
        $this->assertSame(3_000_000, $state['balance']);
        $this->assertNotNull($state['topup_due_at']);

        // Quá hạn nạp bù → tạm ngưng.
        $this->travel((int) config('escrow.topup_days') + 1)->days();
        $this->artisan('escrow:process')->assertSuccessful();
        $this->assertSame('suspended', $this->escrow()['status']);
        $this->travelBack();

        // Trừ quá số dư: phần âm là công nợ.
        $this->postJson($this->url('/deductions'), ['type' => 'deduct_damage', 'amount' => 3_500_000, 'reason' => 'Bồi thường khách', 'is_urgent' => true], $this->adminHeaders)->assertCreated();
        $state = $this->escrow();
        $this->assertSame(-500_000, $state['balance']);
        $this->assertSame(500_000, $state['debt']);
        $this->assertTrue($state['sales_suspended']);
    }

    public function test_deductions_go_through_proposal_dispute_and_resolution(): void
    {
        $this->fund();

        // Đề xuất: số dư chưa đổi, chỉ tính vào "đang tạm giữ". Chỉ Super Admin tạo; khoản khác bắt buộc có chứng từ.
        $this->postJson($this->url('/deductions'), ['type' => 'deduct_penalty', 'amount' => 1, 'reason' => 'x'], $this->ownerHeaders)->assertForbidden();
        $this->postJson($this->url('/deductions'), ['type' => 'deduct_other', 'amount' => 100_000, 'reason' => 'Khoản khác'], $this->adminHeaders)->assertStatus(422);
        $this->postJson($this->url('/deductions'), ['type' => 'deduct_refund', 'amount' => 100_000, 'reason' => 'Hoàn thay'], $this->adminHeaders)->assertStatus(422);
        $id = $this->propose();
        $state = $this->escrow();
        $this->assertSame([self::MIN, 1_000_000, self::MIN - 1_000_000], [$state['balance'], $state['held_amount'], $state['available_amount']]);

        // Nhân viên thường không phản hồi được; chủ đối tác khiếu nại; Super Admin giảm còn 400.000đ → chỉ trừ số đã chốt.
        $this->postJson($this->url("/deductions/{$id}/accept"), [], $this->staffHeaders)->assertForbidden();
        $this->post($this->url("/deductions/{$id}/dispute"), ['reason' => 'Khách tự huỷ', 'evidence' => [$this->evidence()]], $this->ownerHeaders)->assertOk()
            ->assertJsonPath('data.deduction.status', 'disputed')->assertJsonCount(1, 'data.deduction.dispute_evidence');
        $this->postJson($this->url("/deductions/{$id}/accept"), [], $this->ownerHeaders)->assertStatus(422);
        $this->postJson($this->url("/deductions/{$id}/resolve"), ['resolution' => 'reduce', 'final_amount' => 400_000, 'note' => 'Giảm theo biên bản'], $this->adminHeaders)->assertOk()
            ->assertJsonPath('data.deduction.status', 'applied')->assertJsonPath('data.deduction.final_amount', 400_000)->assertJsonPath('data.escrow.balance', self::MIN - 400_000);
        // Chốt là một chiều.
        $this->postJson($this->url("/deductions/{$id}/resolve"), ['resolution' => 'cancel', 'note' => 'Đổi ý'], $this->adminHeaders)->assertStatus(422);

        // Hết hạn phản hồi mà không khiếu nại = đồng ý → tự trừ.
        $expiring = $this->propose(['amount' => 200_000]);
        $this->travel((int) config('escrow.deduction_response_days') + 1)->days();
        $this->artisan('escrow:process')->assertSuccessful();
        $this->travelBack();
        $this->assertSame('applied', $this->getJson($this->url('/deductions'), $this->ownerHeaders)->json('data.0.status'));
        $this->assertSame(self::MIN - 600_000, $this->escrow()['balance']);
        $this->postJson($this->url("/deductions/{$expiring}/dispute"), ['reason' => 'Muộn'], $this->ownerHeaders)->assertStatus(422);

        // Khẩn: trừ ngay; đối tác khiếu nại sau; huỷ thì trả lại bằng bút toán đảo.
        $urgent = $this->propose(['amount' => 300_000, 'is_urgent' => true]);
        $this->assertSame(self::MIN - 900_000, $this->escrow()['balance']);
        $this->postJson($this->url("/deductions/{$urgent}/dispute"), ['reason' => 'Không phải lỗi cơ sở'], $this->ownerHeaders)->assertOk();
        $this->postJson($this->url("/deductions/{$urgent}/resolve"), ['resolution' => 'cancel', 'note' => 'Khiếu nại đúng'], $this->adminHeaders)->assertOk()
            ->assertJsonPath('data.deduction.status', 'cancelled')->assertJsonPath('data.escrow.balance', self::MIN - 600_000);

        // Sổ: mỗi lần đổi số dư là một bút toán, số dư sau khớp tổng; lọc theo loại.
        $entries = PartnerEscrowEntry::where('partner_id', $this->partner->id)->orderBy('id')->get();
        $this->assertSame(['deposit', 'deduct_penalty', 'deduct_penalty', 'deduct_penalty', 'reversal'], $entries->pluck('type')->all());
        $this->assertSame((int) $entries->sum('amount'), (int) $entries->last()->balance_after);
        $this->getJson($this->url('/entries?type=reversal'), $this->ownerHeaders)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 300_000);
    }

    public function test_entries_are_immutable_and_corrected_by_reversal(): void
    {
        $this->fund(6_000_000);
        $entry = PartnerEscrowEntry::where('partner_id', $this->partner->id)->firstOrFail();

        try {
            $entry->update(['amount' => 1]);
            $this->fail('Bút toán không được sửa.');
        } catch (\DomainException) {
        }

        // Đảo bút toán nạp ghi sai: số dư về 0; không đảo hai lần, không đảo bút toán đảo.
        $reversal = $this->postJson($this->url('/entries'), ['type' => 'reversal', 'entry_id' => $entry->id, 'reason' => 'Ghi nhầm đối tác'], $this->adminHeaders)
            ->assertCreated()->assertJsonPath('data.entry.amount', -6_000_000)->assertJsonPath('data.escrow.balance', 0)->json('data.entry.id');
        $this->postJson($this->url('/entries'), ['type' => 'reversal', 'entry_id' => $entry->id, 'reason' => 'Lần nữa'], $this->adminHeaders)->assertStatus(422);
        $this->postJson($this->url('/entries'), ['type' => 'reversal', 'entry_id' => $reversal, 'reason' => 'Đảo của đảo'], $this->adminHeaders)->assertStatus(422);

        // Hoàn ký quỹ không vượt số dư khả dụng (đã trừ phần đang tạm giữ).
        $this->post($this->url('/entries'), ['type' => 'deposit', 'amount' => 2_000_000, 'reason' => 'Nạp lại', 'evidence' => [$this->evidence()]], $this->adminHeaders)->assertCreated();
        $this->propose(['amount' => 500_000]);
        $this->postJson($this->url('/entries'), ['type' => 'withdraw', 'amount' => 1_800_000, 'reason' => 'Chấm dứt hợp đồng'], $this->adminHeaders)->assertStatus(422);
        $this->postJson($this->url('/entries'), ['type' => 'withdraw', 'amount' => 1_500_000, 'reason' => 'Chấm dứt hợp đồng'], $this->adminHeaders)->assertCreated()->assertJsonPath('data.escrow.balance', 500_000);

        // Tài khoản của đối tác khác không xem được.
        $other = Partner::create(['partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Đối tác khác', 'status' => true]);
        $this->getJson("/api/admin/partners/{$other->id}/escrow", $this->ownerHeaders)->assertForbidden();
    }

    public function test_partner_deposits_by_qr_and_the_webhook_credits_once(): void
    {
        $this->putJson($this->url('/settings'), ['min_amount' => self::MIN, 'enforce_from' => now()->subMinute()->toIso8601String()], $this->adminHeaders)->assertOk();

        $this->postJson($this->url('/deposit-link'), ['amount' => 1000], $this->ownerHeaders)->assertStatus(422);
        $this->postJson($this->url('/deposit-link'), ['amount' => self::MIN], $this->staffHeaders)->assertForbidden();
        $code = $this->postJson($this->url('/deposit-link'), ['amount' => self::MIN], $this->ownerHeaders)->assertCreated()
            ->assertJsonPath('data.status', 'pending')->json('data.transaction_code');
        $this->assertMatchesRegularExpression('/^KQ[A-HJ-NP-Z2-9]{6}$/', $code);

        // Webhook PayOS (đã qua bước xác thực chữ ký ở PaymentController): cộng đúng số tiền thực nhận, gọi lặp không cộng hai lần.
        $payload = ['code' => '00', 'data' => ['orderCode' => 123, 'amount' => self::MIN, 'description' => "CK {$code} nap ky quy", 'reference' => 'FT123', 'code' => '00']];
        $service = app(EscrowPayosService::class);
        $this->assertSame(0, $service->handleWebhook($payload)['error']);
        $service->handleWebhook($payload);

        $this->assertSame(PartnerEscrowDeposit::STATUS_PAID, PartnerEscrowDeposit::where('transaction_code', $code)->value('status'));
        $this->assertSame(1, PartnerEscrowEntry::where('partner_id', $this->partner->id)->count());
        $state = $this->escrow();
        $this->assertSame([self::MIN, 'ok'], [$state['balance'], $state['status']]);
        $this->getJson($this->url('/deposits'), $this->ownerHeaders)->assertOk()->assertJsonPath('data.0.status', 'paid')->assertJsonPath('data.0.payment', null);

        // Giao dịch không phải ký quỹ → để luồng khác xử lý.
        $this->assertNull($service->handleWebhook(['code' => '00', 'data' => ['orderCode' => 456, 'amount' => 1, 'description' => 'don dat phong']]));
    }
}
