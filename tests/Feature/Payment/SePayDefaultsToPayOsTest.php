<?php

namespace Tests\Feature\Payment;

use App\Models\Partner;
use App\Services\Payment\PayOsAccountResolver;
use App\Services\Payment\SePayGateway;
use App\Services\Payment\SePayService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;
use Tests\TestCase;

// MẶC ĐỊNH LÀ PAYOS: chỉ đối tác nào có tài khoản SePay đang bật (và đủ điều kiện) mới dùng SePay; mọi đối tác/đơn khác giữ nguyên luồng PayOS cũ.
class SePayDefaultsToPayOsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Settings\SePaySettings::fake(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null]); // tách khỏi cấu hình CMS thật
    }

    private function partners(): array
    {
        $partners = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->limit(2)->get();

        if ($partners->count() < 2) {
            $this->markTestSkipped('Cần ít nhất 2 đối tác Homestay thường.');
        }

        foreach ($partners as $partner) {
            $partner->forceFill(['payment_flow_effective_at' => now()->subDay()])->save();
            PartnerSePayAccount::query()->where('partner_id', $partner->id)->delete();
        }

        return [$partners[0], $partners[1]];
    }

    private function order(Partner $partner): Order
    {
        $branch = Category::withoutGlobalScopes()->forceCreate(['name' => 'CN mac dinh ' . Str::random(4), 'slug' => 'cn-md-' . Str::random(6), 'category_type' => 'product', 'status' => 1, 'partner_id' => $partner->id]);

        return Order::withoutGlobalScopes()->forceCreate([
            'partner_id' => $partner->id, 'category_id' => $branch->id, 'status' => 'pending', 'amount' => 500000, 'full_amount' => 500000,
            'buyer_name' => 'Khach', 'buyer_phone' => '0900000000', 'payment_method' => 'PayOS', 'order_status' => 'pending',
        ]);
    }

    private function assertPayOs(Order $order): void
    {
        $sePay = app(SePayService::class);

        $this->assertNotInstanceOf(SePayGateway::class, PayOsAccountResolver::forOrder($order), 'phải là cổng PayOS');
        $this->assertFalse($sePay->usesSePay($order));
        $this->assertSame(2000, $sePay->minAmount($order), 'PayOS giữ mức tối thiểu 2.000đ');
        $this->assertSame(['payment_provider' => 'payos', 'sepay' => null], $sePay->paymentFields($order));
        $this->assertSame(0, SePayPaymentLink::query()->where('order_id', $order->id)->count());
    }

    public function test_a_partner_without_a_sepay_account_stays_on_payos(): void
    {
        [$p1] = $this->partners();

        $this->assertPayOs($this->order($p1));
        $this->assertSame(0, PartnerSePayAccount::query()->where('partner_id', $p1->id)->count());
    }

    public function test_enabling_sepay_for_one_partner_does_not_change_any_other_partner(): void
    {
        [$withSePay, $other] = $this->partners();
        PartnerSePayAccount::query()->where('account_number', '0011009998887')->delete();
        PartnerSePayAccount::query()->create(['partner_id' => $withSePay->id, 'is_active' => true, 'bank_code' => 'VCB', 'bank_bin' => '970436', 'account_number' => '0011009998887', 'account_holder' => 'A']);

        $this->assertInstanceOf(SePayGateway::class, PayOsAccountResolver::forOrder($this->order($withSePay)));
        $this->assertPayOs($this->order($other));
    }

    public function test_a_disabled_or_not_yet_eligible_sepay_account_stays_on_payos(): void
    {
        [$p1] = $this->partners();
        PartnerSePayAccount::query()->where('account_number', '0011009998887')->delete();
        $account = PartnerSePayAccount::query()->create(['partner_id' => $p1->id, 'is_active' => false, 'bank_code' => 'VCB', 'bank_bin' => '970436', 'account_number' => '0011009998887', 'account_holder' => 'A']);

        $this->assertPayOs($this->order($p1));

        $account->update(['is_active' => true]);
        $p1->forceFill(['payment_flow_effective_at' => null])->save();
        $this->assertPayOs($this->order($p1->fresh()));
    }

    public function test_orders_without_a_partner_and_the_kill_switch_stay_on_payos(): void
    {
        [$p1] = $this->partners();
        PartnerSePayAccount::query()->where('account_number', '0011009998887')->delete();
        PartnerSePayAccount::query()->create(['partner_id' => $p1->id, 'is_active' => true, 'bank_code' => 'VCB', 'bank_bin' => '970436', 'account_number' => '0011009998887', 'account_holder' => 'A']);

        $noPartner = Order::withoutGlobalScopes()->forceCreate(['status' => 'pending', 'amount' => 100000, 'full_amount' => 100000, 'buyer_name' => 'K', 'buyer_phone' => '0900000000', 'payment_method' => 'PayOS', 'order_status' => 'pending']);
        $this->assertPayOs($noPartner);

        config(['sepay.enabled' => false]);
        $this->assertPayOs($this->order($p1));
    }
}
