<?php

namespace Tests\Feature\Payment;

use App\Models\Partner;
use App\Models\PartnerSettlement;
use App\Services\Payment\PlatformCollectionService;
use App\Services\SettlementPayosService;
use App\Settings\Pay2SSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Modules\Minihouse\App\Services\InvoicePayOsService;
use Modules\Payment\Entities\SePayTransaction;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Services\VehicleBookingService;
use Modules\VehicleRental\App\Services\VehiclePayOsService;
use Modules\VehicleRental\App\Services\VehicleRatePlanDefaults;
use Tests\TestCase;

// Các khoản 365 thu hộ còn lại qua Pay2S: nộp hoa hồng đối soát, thuê xe, hoá đơn MiniHouse (tài khoản 365 hoặc tài khoản ngân hàng riêng của chủ toà, đổi qua lại PayOS ⇄ Pay2S).
class Pay2SPlatformKindsTest extends TestCase
{
    use DatabaseTransactions;

    private const PLATFORM_ACCOUNT = '8800112233';

    private const PLATFORM_TOKEN = 'platform-hook-token-kinds-zz';

    private const OWNER_ACCOUNT = '7700445566';

    private const OWNER_TOKEN = 'owner-hook-token-kinds-yyyy';

    private int $seq = 920000000;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Settings\SePaySettings::fake(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null]);
        $this->fakePay2S('pay2s');
        config(['pay2s.api_token' => null, 'pay2s.enabled' => true, 'payos.client_id' => null, 'payos.api_key' => null, 'payos.checksum_key' => null]);
    }

    private function fakePay2S(string $gateway): void
    {
        Pay2SSettings::fake([
            'enabled' => null, 'api_token' => null, 'default_gateway' => $gateway,
            'platform_bank_code' => 'VCB', 'platform_bank_bin' => '970436', 'platform_account_number' => self::PLATFORM_ACCOUNT,
            'platform_account_holder' => 'CONG TY 365HOME', 'platform_webhook_token' => self::PLATFORM_TOKEN,
        ]);
    }

    /** Gửi 1 giao dịch tiền vào qua webhook Pay2S; trả mã giao dịch (sepay_id) để tra nhật ký. */
    private function hook(string $content, int $amount, string $account = self::PLATFORM_ACCOUNT, string $token = self::PLATFORM_TOKEN): int
    {
        $id = ++$this->seq;
        $tx = [
            'id' => $id, 'gateway' => 'VCB', 'transactionDate' => now()->format('Y-m-d H:i:s'), 'transactionNumber' => (string) random_int(10000, 99999),
            'accountNumber' => $account, 'content' => 'CK ' . $content, 'transferType' => 'IN', 'transferAmount' => $amount,
        ];

        $this->call('POST', '/api/webhook/pay2s', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode(['transactions' => [$tx]]))->assertOk();

        return $id;
    }

    private function row(int $id): ?SePayTransaction
    {
        return SePayTransaction::query()->where('provider', 'pay2s')->where('sepay_id', $id)->first();
    }

    // ── Nộp hoa hồng đối soát ──────────────────────────────────────────────

    private function settlement(int $net): PartnerSettlement
    {
        $partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->firstOrFail();

        return PartnerSettlement::query()->create([
            'code' => 'DS' . now()->format('ym') . '-' . substr(str_replace(['I', 'O', '0', '1'], 'A', strtoupper(Str::random(8))), 0, 5), 'partner_id' => $partner->id,
            'period_start' => now()->subMonth()->startOfMonth(), 'period_end' => now()->subMonth()->endOfMonth(), 'cycle' => 'monthly',
            'status' => PartnerSettlement::STATUS_SENT, 'net_amount' => $net, 'sent_at' => now(), 'due_at' => now()->addDays(7),
        ]);
    }

    public function test_settlement_is_paid_by_a_transfer_that_covers_the_net_amount_only(): void
    {
        $settlement = $this->settlement(750000);
        $settlement = app(SettlementPayosService::class)->createLink($settlement);

        $this->assertSame(self::PLATFORM_ACCOUNT, $settlement->payos_account_number);
        $this->assertStringContainsString($settlement->code, (string) $settlement->payos_checkout_url);

        // Thiếu tiền (gõ mã không dấu gạch ngang như một số ngân hàng): từ chối, chưa chốt.
        $short = $this->hook(str_replace('-', '', $settlement->code), 100000);
        $this->assertSame('rejected', $this->row($short)->status);
        $this->assertSame(PartnerSettlement::STATUS_SENT, $settlement->fresh()->status);

        $ok = $this->hook($settlement->code, 750000);
        $this->assertSame('processed', $this->row($ok)->status);
        $this->assertSame(PartnerSettlement::STATUS_PAID, $settlement->fresh()->status);

        // Chuyển trùng: ghi từ chối để hoàn tiền thủ công.
        $dup = $this->hook($settlement->code, 750000);
        $this->assertSame('rejected', $this->row($dup)->status);
    }

    // ── Thuê xe ────────────────────────────────────────────────────────────

    public function test_vehicle_rental_gets_pay2s_instructions_and_is_confirmed_by_the_booking_code(): void
    {
        VehicleBooking::query()->where('status', VehicleBooking::STATUS_PENDING_PAYMENT)->delete();
        $branchId = DB::table('categories')->value('id');
        $vehicle = Vehicle::create([
            'branch_id' => (int) $branchId, 'type' => 'motorbike', 'brand' => 'Honda', 'model' => 'Vision',
            'plate_display' => '88-' . strtoupper(Str::random(2)) . ' ' . random_int(100, 999) . '.' . random_int(10, 99), 'deposit_amount' => 2000000,
        ]);
        VehicleRatePlanDefaults::seed($vehicle);
        $start = now()->addDays(3)->setTimeFromTimeString('09:00');
        $booking = app(VehicleBookingService::class)->create((string) Str::uuid(), $vehicle, $start, $start->copy()->addHours(3));

        $booking = app(VehiclePayOsService::class)->createLink($booking);

        $this->assertNull($booking->payos_order_code);
        $this->assertStringContainsString($booking->code, (string) $booking->payos_checkout_url);
        $this->assertStringStartsWith('000201', (string) $booking->payos_qr_code);

        $short = $this->hook($booking->code, 1000);
        $this->assertSame('rejected', $this->row($short)->status);
        $this->assertSame(VehicleBooking::STATUS_PENDING_PAYMENT, $booking->fresh()->status);

        $ok = $this->hook($booking->code, (int) $booking->total_amount);
        $this->assertSame('processed', $this->row($ok)->status);
        $this->assertSame(VehicleBooking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    // ── Hoá đơn MiniHouse ──────────────────────────────────────────────────

    private function building(?string $method, bool $ownerBank = true): Building
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);

        return Building::create([
            'zone_id' => $zone->id, 'name' => 'B' . uniqid(), 'address' => 'a', 'electric_unit_price' => 3500, 'water_unit_price' => 15000,
            'payment_method' => $method,
            'owner_bank_bin' => $ownerBank ? '970415' : null, 'owner_bank_name' => $ownerBank ? 'VietinBank' : null,
            'owner_bank_account_number' => $ownerBank ? self::OWNER_ACCOUNT : null, 'owner_bank_account_holder' => $ownerBank ? 'NGUYEN VAN CHU' : null,
            'pay2s_webhook_token' => $method === Building::PAYMENT_METHOD_PAY2S ? self::OWNER_TOKEN : null,
        ]);
    }

    private function invoice(Building $building, int $amount = 3000000): Invoice
    {
        $room = Room::create(['building_id' => $building->id, 'code' => 'P-' . random_int(100, 999), 'price' => $amount, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '0911112222', 'room_id' => $room->id]);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'start_date' => now()->subMonth(), 'monthly_price' => $amount, 'deposit_amount' => $amount,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        return Invoice::create([
            'contract_id' => $contract->id, 'month' => now()->startOfMonth(), 'room_price' => $amount, 'total_amount' => $amount, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID,
        ]);
    }

    public function test_a_building_on_its_own_pay2s_account_collects_into_the_owner_bank_and_confirms_the_invoice(): void
    {
        $building = $this->building(Building::PAYMENT_METHOD_PAY2S);
        $this->assertSame(Building::PAYMENT_METHOD_PAY2S, $building->activePaymentMethod());
        $invoice = $this->invoice($building);

        $result = InvoicePayOsService::createQr($invoice);
        $invoice->refresh();
        $content = PlatformCollectionService::MINIHOUSE_PREFIX . $invoice->payos_order_code;

        $this->assertStringStartsWith('9', (string) $invoice->payos_order_code);
        $this->assertStringContainsString(self::OWNER_ACCOUNT, (string) app(PlatformCollectionService::class)->find('minihouse', $content)['bank']['account_number']);
        $this->assertStringStartsWith('000201', $result['qr_code']);
        $this->assertStringContainsString($content, $result['checkout_url']);

        // Tiền vào tài khoản 365 cho hoá đơn của toà đang thu qua Pay2S riêng: từ chối để đối soát.
        $wrong = $this->hook($content, 3000000);
        $this->assertSame('rejected', $this->row($wrong)->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        // Sai số tiền.
        $short = $this->hook($content, 1000000, self::OWNER_ACCOUNT, self::OWNER_TOKEN);
        $this->assertSame('rejected', $this->row($short)->status);

        // Đúng tài khoản + token của toà, đủ tiền → xác nhận hoá đơn.
        $ok = $this->hook($content, 3000000, self::OWNER_ACCOUNT, self::OWNER_TOKEN);
        $this->assertSame('processed', $this->row($ok)->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_a_building_without_its_own_channel_collects_into_the_platform_account_while_pay2s_is_the_gateway(): void
    {
        $building = $this->building(null, ownerBank: false);
        $invoice = $this->invoice($building);

        InvoicePayOsService::createQr($invoice);
        $invoice->refresh();
        $content = PlatformCollectionService::MINIHOUSE_PREFIX . $invoice->payos_order_code;

        $this->assertSame(self::PLATFORM_ACCOUNT, app(PlatformCollectionService::class)->find('minihouse', $content)['bank']['account_number']);

        // Tiền vào tài khoản riêng của một toà khác không được xác nhận hoá đơn này.
        $other = $this->building(Building::PAYMENT_METHOD_PAY2S);
        $wrongAccount = $this->hook($content, 3000000, self::OWNER_ACCOUNT, self::OWNER_TOKEN);
        $this->assertSame('rejected', $this->row($wrongAccount)->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertNotNull($other);

        $ok = $this->hook($content, 3000000);
        $this->assertSame('processed', $this->row($ok)->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_the_building_payment_method_can_be_switched_between_payos_and_pay2s(): void
    {
        $building = $this->building(Building::PAYMENT_METHOD_PAYOS);
        $building->update(['payos_client_id' => 'c', 'payos_api_key' => 'k', 'payos_checksum_key' => 's']);
        $building->refresh();

        // Chọn PayOS riêng: giữ PayOS dù 365 đang thu qua Pay2S.
        $this->assertSame(Building::PAYMENT_METHOD_PAYOS, $building->activePaymentMethod());

        $building->update(['payment_method' => Building::PAYMENT_METHOD_PAY2S]);
        $building->refresh();
        // Chưa có token webhook Pay2S: chưa dùng được (không tự rơi sang Pay2S nửa vời).
        $this->assertNull($building->activePaymentMethod());

        $building->update(['pay2s_webhook_token' => self::OWNER_TOKEN]);
        $building->refresh();
        $this->assertSame(Building::PAYMENT_METHOD_PAY2S, $building->activePaymentMethod());
        $this->assertTrue($building->hasOwnPay2S());

        $building->update(['payment_method' => Building::PAYMENT_METHOD_PAYOS]);
        $this->assertSame(Building::PAYMENT_METHOD_PAYOS, $building->fresh()->activePaymentMethod());
    }

    public function test_one_buildings_token_cannot_confirm_an_invoice_of_another_building_or_the_platform_account(): void
    {
        $mine = $this->building(Building::PAYMENT_METHOD_PAY2S);
        $invoice = $this->invoice($mine);
        InvoicePayOsService::createQr($invoice);
        $content = PlatformCollectionService::MINIHOUSE_PREFIX . $invoice->fresh()->payos_order_code;

        // Chủ toà khác (token riêng, tài khoản riêng) báo giao dịch vào tài khoản của toà này: bỏ qua.
        $other = $this->building(Building::PAYMENT_METHOD_PAY2S);
        $other->update(['owner_bank_account_number' => '7700000099', 'pay2s_webhook_token' => 'other-building-token-uuuuuuu']);
        $forged = $this->hook($content, 3000000, self::OWNER_ACCOUNT, 'other-building-token-uuuuuuu');
        $this->assertNull($this->row($forged));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        // Token của toà báo giao dịch vào tài khoản 365: bỏ qua.
        $forgedPlatform = $this->hook($content, 3000000, self::PLATFORM_ACCOUNT, self::OWNER_TOKEN);
        $this->assertNull($this->row($forgedPlatform));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }
}
