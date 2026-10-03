<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTtlockPasscode;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Transaction;
use Modules\Minihouse\App\Models\TtlockSetting;
use Modules\Minihouse\App\Models\Zone;
use Modules\Minihouse\App\Services\ContractDepositService;
use Modules\Minihouse\App\Services\ContractTtlockService;
use Modules\Minihouse\App\Services\TenantRoomUnlockService;
use Tests\TestCase;

// Khoá CỔNG của toà nhà + thời điểm cấp mã theo hợp đồng — xem ContractTtlockService/TtlockSetting.
// HTTP ra TTLock được fake toàn bộ: khoá phòng = 111, khoá cổng = 222, TTLock tự sinh mã "481957".
class ContractGateLockTtlockTest extends TestCase
{
    use DatabaseTransactions;

    private const ROOM_LOCK = 111;
    private const GATE_LOCK = 222;

    private Room $room;

    private function setUpBuilding(array $setting, ?int $roomLock = self::ROOM_LOCK): void
    {
        Cache::flush();
        Http::fake([
            '*/oauth2/token'          => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7200]),
            '*/v3/keyboardPwd/get'    => Http::response(['keyboardPwd' => '481957', 'keyboardPwdId' => 77]),
            '*/v3/keyboardPwd/add'    => Http::response(['keyboardPwdId' => 88]),
            '*/v3/keyboardPwd/delete' => Http::response(['errcode' => 0]),
            '*/v3/keyboardPwd/change' => Http::response(['errcode' => 0]),
            '*/v3/lock/unlock'        => Http::response(['errcode' => 0]),
            '*/v3/lock/list*'         => Http::response(['list' => [['lockId' => self::GATE_LOCK, 'lockAlias' => 'Cong chinh']], 'pages' => 1]),
        ]);

        $zone     = Zone::create(['name' => 'Khu ' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'Toa ' . uniqid(), 'address' => 'Dia chi']);

        TtlockSetting::create([
            'building_id' => $building->id, 'client_id' => 'cid', 'client_secret' => 'secret',
            'username' => 'user@test.local', 'password_md5' => md5('x'), 'is_active' => true,
            'gate_lock_ids' => [self::GATE_LOCK],
            ...$setting,
        ]);

        $this->room = Room::create([
            'building_id' => $building->id, 'code' => 'G' . random_int(100, 999), 'status' => Room::STATUS_EMPTY,
            'lock_id' => $roomLock,
        ]);
    }

    private function makeContract(float $deposit = 0): Contract
    {
        $tenant = Tenant::create(['fullname' => 'Khach', 'phone' => '09' . random_int(10000000, 99999999)]);

        return Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $tenant->id,
            'start_date' => now(), 'end_date' => now()->addMonths(6),
            'monthly_price' => 3000000, 'deposit_amount' => $deposit,
            'status' => Contract::STATUS_ACTIVE,
        ]);
    }

    private function rows(Contract $contract)
    {
        return ContractTtlockPasscode::where('contract_id', $contract->id)->get()->keyBy('lock_id');
    }

    public function test_shared_mode_issues_the_room_code_on_the_gate_lock_too(): void
    {
        $this->setUpBuilding(['gate_code_mode' => TtlockSetting::GATE_CODE_SHARED]);

        $contract = $this->makeContract();
        $rows     = $this->rows($contract);

        $this->assertCount(2, $rows);
        $this->assertFalse($rows[self::ROOM_LOCK]->is_gate);
        $this->assertTrue($rows[self::GATE_LOCK]->is_gate);
        $this->assertSame('481957', $rows[self::ROOM_LOCK]->code);
        $this->assertSame('481957', $rows[self::GATE_LOCK]->code);
        $this->assertFalse(ContractTtlockService::hasSeparateGateCode($contract));
    }

    public function test_separate_mode_gives_the_gate_its_own_code_and_changes_it_independently(): void
    {
        $this->setUpBuilding(['gate_code_mode' => TtlockSetting::GATE_CODE_SEPARATE]);

        $contract = $this->makeContract();
        $codes    = ContractTtlockService::currentCodes($contract);

        $this->assertSame('481957', $codes['room']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $codes['gate']);
        $this->assertTrue(ContractTtlockService::hasSeparateGateCode($contract));

        $result = ContractTtlockService::regenerateCode($contract, '739152', 'gate');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(['room' => '481957', 'gate' => '739152'], ContractTtlockService::currentCodes($contract));
    }

    public function test_gate_only_building_still_issues_a_gate_code(): void
    {
        $this->setUpBuilding([], roomLock: null);

        $contract = $this->makeContract();
        $rows     = $this->rows($contract);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[self::GATE_LOCK]->is_gate);
        $this->assertSame($rows[self::GATE_LOCK]->code, ContractTtlockService::currentCode($contract));
    }

    // Khoá cổng của toà cũng chính là khoá phòng -> hợp đồng chỉ có 1 loại mã; target=gate vẫn phải đổi
    // được (đổi tất cả), không được báo lỗi "kiểm tra khoá còn kết nối mạng".
    public function test_gate_target_changes_the_only_code_when_gate_lock_is_the_room_lock(): void
    {
        $this->setUpBuilding(['gate_code_mode' => TtlockSetting::GATE_CODE_SEPARATE], roomLock: self::GATE_LOCK);

        $contract = $this->makeContract();

        $this->assertFalse(ContractTtlockService::hasSeparateGateCode($contract));

        $result = ContractTtlockService::regenerateCode($contract, '739152', 'gate');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('739152', ContractTtlockService::currentCode($contract));

        // Toà đã cấu hình khoá cổng -> khách vẫn thấy mã cổng (trùng mã phòng) và mở cổng từ xa được.
        $this->assertSame(['room' => '739152', 'gate' => '739152'], ContractTtlockService::currentCodes($contract));

        $service = app(TenantRoomUnlockService::class);
        $this->assertSame(['room', 'gate'], array_column($service->locks($contract), 'target'));
        $this->assertTrue($service->unlock(Tenant::find($contract->tenant_id), $contract, 'gate')['success']);
    }

    // TTLock từ chối tự sinh lại mã cho đúng khung giờ vừa xoá (errcode -1026) -> phải tự sinh số ngẫu
    // nhiên thay thế, không để hợp đồng mất mã.
    public function test_regenerate_falls_back_to_a_random_code_when_ttlock_refuses_to_generate(): void
    {
        $this->setUpBuilding(['gate_lock_ids' => []]);

        $contract = $this->makeContract();
        $this->assertSame('481957', ContractTtlockService::currentCode($contract));

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            '*/oauth2/token'          => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7200]),
            '*/v3/keyboardPwd/get'    => Http::response(['errcode' => -1026, 'errmsg' => 'Passcode with this validity period has been generated before and deleted.']),
            '*/v3/keyboardPwd/add'    => Http::response(['keyboardPwdId' => 99]),
            '*/v3/keyboardPwd/delete' => Http::response(['errcode' => 0]),
        ]);

        $result = ContractTtlockService::regenerateCode($contract);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $result['code']);
        $this->assertNotSame('481957', $result['code']);
        $this->assertCount(1, $this->rows($contract));
    }

    public function test_payment_mode_waits_for_the_deposit(): void
    {
        $this->setUpBuilding(['issue_mode' => TtlockSetting::ISSUE_ON_PAYMENT]);

        $contract = $this->makeContract(deposit: 2000000);

        $this->assertCount(0, $this->rows($contract));
        $this->assertFalse(ContractTtlockService::canChangeCode($contract));

        $this->assertTrue(ContractDepositService::markPaid($contract));

        $this->assertCount(2, $this->rows($contract));
        $this->assertNotNull($contract->fresh()->deposit_paid_at);
        $this->assertSame(1, Transaction::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->where('type', Transaction::TYPE_IN)
            ->where('category', Transaction::CATEGORY_DEPOSIT)
            ->where('amount', 2000000)
            ->count());

        // Bấm lần 2 không ghi trùng.
        $this->assertFalse(ContractDepositService::markPaid($contract->fresh()));
    }

    public function test_payment_mode_without_deposit_issues_when_the_first_invoice_is_paid(): void
    {
        $this->setUpBuilding(['issue_mode' => TtlockSetting::ISSUE_ON_PAYMENT]);

        $contract = $this->makeContract();
        $invoice  = Invoice::create([
            'contract_id' => $contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'status' => Invoice::STATUS_UNPAID,
        ]);

        $this->assertCount(0, $this->rows($contract));

        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'amount' => 3000000, 'paid_at' => now(),
            'payment_method' => InvoicePayment::METHOD_CASH, 'status' => InvoicePayment::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $this->assertCount(2, $this->rows($contract));
    }

    public function test_tenant_sees_both_codes_and_can_unlock_room_or_gate(): void
    {
        $this->setUpBuilding(['gate_code_mode' => TtlockSetting::GATE_CODE_SEPARATE]);

        $contract = $this->makeContract();
        $tenant   = Tenant::find($contract->tenant_id);
        $service  = app(TenantRoomUnlockService::class);

        $this->assertSame([
            ['target' => 'room', 'lock_id' => self::ROOM_LOCK, 'name' => 'Phòng ' . $this->room->code],
            ['target' => 'gate', 'lock_id' => self::GATE_LOCK, 'name' => 'Cong chinh'],
        ], $service->locks($contract));

        $this->assertTrue($service->unlock($tenant, $contract)['success']);
        $this->assertTrue($service->unlock($tenant, $contract, 'gate', self::GATE_LOCK)['success']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/lock/unlock') && (int) $request['lockId'] === self::GATE_LOCK);

        // lock_id không thuộc danh sách khoá cổng của toà -> từ chối, không gọi TTLock.
        $this->assertSame(422, $service->unlock($tenant, $contract, 'gate', 999)['status']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v3/lock/unlock') && (int) $request['lockId'] === 999);

        $codes = ContractTtlockService::currentCodes($contract);

        $this->actingAs($tenant, 'tenant')
            ->get('/minihouse/portal/contracts/' . $contract->id)
            ->assertOk()
            ->assertSee('Mã phòng')
            ->assertSee($codes['room'])
            ->assertSee($codes['gate'])
            ->assertSee('Mở khóa cổng — Cong chinh');
    }

    public function test_tenant_cannot_unlock_before_payment_when_building_requires_it(): void
    {
        $this->setUpBuilding(['issue_mode' => TtlockSetting::ISSUE_ON_PAYMENT]);

        $contract = $this->makeContract(deposit: 2000000);
        $tenant   = Tenant::find($contract->tenant_id);
        $service  = app(TenantRoomUnlockService::class);

        $this->assertSame('payment_required', $service->unlock($tenant, $contract, 'gate')['type']);

        ContractDepositService::markPaid($contract);

        $this->assertTrue($service->unlock($tenant, $contract->fresh(), 'gate')['success']);
    }

    public function test_removing_the_gate_lock_revokes_gate_codes_of_active_contracts(): void
    {
        $this->setUpBuilding([]);

        $contract = $this->makeContract();
        $this->assertCount(2, $this->rows($contract));

        TtlockSetting::find($this->room->building_id)->update(['gate_lock_ids' => []]);
        ContractTtlockService::syncForBuilding((int) $this->room->building_id);

        $rows = $this->rows($contract);
        $this->assertCount(1, $rows);
        $this->assertTrue($rows->has(self::ROOM_LOCK));
    }
}
