<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// CCCD cấp ngày/nơi cấp của 2 bên: hồ sơ Khách thuê (id_card_issued_*) và hồ sơ Toà
// (owner_id_card_issued_*) là nơi DUY NHẤT ghi; bản hợp đồng điện tử chỉ đọc lại. Khoá luôn bug
// BuildingSetting::$fillable thiếu 2 cột owner_id_card_issued_* khiến Building::update() âm thầm
// bỏ qua, không bao giờ lưu được.
class IdCardIssuedFieldsApiTest extends TestCase
{
    use DatabaseTransactions;

    private function adminHeaders(): array
    {
        $admin = User::role('super_admin')->first();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    public function test_tenant_create_update_and_show_issued_fields(): void
    {
        $headers = $this->adminHeaders();

        $id = $this->withHeaders($headers)->post('/api/admin/minihouse/tenants', [
            'fullname'             => 'Khách CCCD',
            'id_card_issued_date'  => '2021-03-15',
            'id_card_issued_place' => 'Cục CS QLHC về TTXH',
        ])->assertCreated()
            ->assertJsonPath('data.id_card_issued_date', '2021-03-15')
            ->json('data.id');

        $this->withHeaders($headers)->getJson("/api/admin/minihouse/tenants/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id_card_issued_date', '2021-03-15')
            ->assertJsonPath('data.id_card_issued_place', 'Cục CS QLHC về TTXH');

        // Multipart gửi chuỗi rỗng => lưu null.
        $this->withHeaders($headers)->post("/api/admin/minihouse/tenants/{$id}", [
            'id_card_issued_date'  => '',
            'id_card_issued_place' => '',
        ])->assertOk()
            ->assertJsonPath('data.id_card_issued_date', null)
            ->assertJsonPath('data.id_card_issued_place', null);
    }

    public function test_tenant_issued_fields_validation(): void
    {
        $tenant = Tenant::create(['fullname' => 'Khách V']);

        $this->withHeaders($this->adminHeaders())->postJson("/api/admin/minihouse/tenants/{$tenant->id}", [
            'id_card_issued_date'  => now()->addDay()->toDateString(),
            'id_card_issued_place' => str_repeat('a', 256),
        ])->assertStatus(422)->assertJsonValidationErrors(['id_card_issued_date', 'id_card_issued_place']);

        $this->withHeaders($this->adminHeaders())->postJson("/api/admin/minihouse/tenants/{$tenant->id}", [
            'id_card_issued_date' => 'khong-phai-ngay',
        ])->assertStatus(422)->assertJsonValidationErrors(['id_card_issued_date']);
    }

    public function test_building_update_persists_owner_issued_fields(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $headers = $this->adminHeaders();

        $this->withHeaders($headers)->patchJson("/api/admin/minihouse/buildings/{$building->id}", [
            'owner_id_card_issued_date'  => '2020-01-02',
            'owner_id_card_issued_place' => 'Công an TP.HCM',
        ])->assertOk();

        $this->withHeaders($headers)->getJson("/api/admin/minihouse/buildings/{$building->id}")
            ->assertOk()
            ->assertJsonPath('data.owner_id_card_issued_date', '2020-01-02')
            ->assertJsonPath('data.owner_id_card_issued_place', 'Công an TP.HCM');

        $this->withHeaders($headers)->patchJson("/api/admin/minihouse/buildings/{$building->id}", [
            'owner_id_card_issued_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['owner_id_card_issued_date']);
    }

    public function test_contract_document_reads_issued_fields_but_does_not_write_them(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create([
            'zone_id' => $zone->id, 'name' => 'B', 'address' => 'a',
            'owner_id_card_issued_date' => '2019-05-06', 'owner_id_card_issued_place' => 'Nơi cấp chủ',
        ]);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create([
            'fullname' => 'Khách', 'id_card_issued_date' => '2021-03-15', 'id_card_issued_place' => 'Nơi cấp khách',
        ]);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(11),
            'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
        $headers = $this->adminHeaders();
        $url = "/api/admin/minihouse/contracts/{$contract->id}/document";

        $this->withHeaders($headers)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.tenant_id_card_issued_date', '2021-03-15')
            ->assertJsonPath('data.tenant_id_card_issued_place', 'Nơi cấp khách')
            ->assertJsonPath('data.owner_id_card_issued_date', '2019-05-06')
            ->assertJsonPath('data.owner_id_card_issued_place', 'Nơi cấp chủ');

        $this->withHeaders($headers)->patchJson($url, [
            'payment_day'                 => 5,
            'tenant_id_card_issued_date'  => '2000-01-01',
            'tenant_id_card_issued_place' => 'Ghi đè',
            'owner_id_card_issued_date'   => '2000-01-01',
            'owner_id_card_issued_place'  => 'Ghi đè',
        ])->assertOk()->assertJsonPath('data.payment_day', 5);

        $this->assertSame('2021-03-15', $tenant->fresh()->id_card_issued_date->toDateString());
        $this->assertSame('Nơi cấp khách', $tenant->fresh()->id_card_issued_place);
        $this->assertSame('Nơi cấp chủ', Building::withoutGlobalScopes()->find($building->id)->owner_id_card_issued_place);

        // Draft đọc sống: sửa ở hồ sơ khách thì bản hợp đồng thấy ngay.
        $this->withHeaders($headers)->patchJson("/api/admin/minihouse/tenants/{$tenant->id}", [
            'id_card_issued_place' => 'Nơi cấp mới',
        ])->assertOk();

        $this->withHeaders($headers)->getJson($url)
            ->assertJsonPath('data.tenant_id_card_issued_place', 'Nơi cấp mới');
    }
}
