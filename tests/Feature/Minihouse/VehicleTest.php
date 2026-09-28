<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Minihouse\App\Filament\Resources\ContractResource\Pages\EditContract;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Vehicle;
use Modules\Minihouse\App\Models\VehicleRate;
use Modules\Minihouse\App\Models\Zone;
use Modules\Minihouse\App\Services\InvoiceGenerationService;
use Modules\Minihouse\App\Services\VehicleService;
use Tests\TestCase;

// Xe khách thuê (bản đơn giản: biển số + loại xe + tên xe): bảng giá theo toà, cảnh báo vượt giới hạn,
// phí vào hoá đơn, khách tự khai + duyệt, tự ngưng khi hợp đồng kết thúc, tab "Phương tiện" trên hợp
// đồng, và các trang quản trị/portal.
class VehicleTest extends TestCase
{
    use DatabaseTransactions;

    private Building $building;
    private Room $room;
    private Tenant $tenant;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $this->building = Building::create(['zone_id' => $zone->id, 'name' => 'Toa xe ' . uniqid(), 'address' => 'a']);
        $this->room     = Room::create(['building_id' => $this->building->id, 'code' => 'X-' . random_int(100, 999), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $this->tenant   = Tenant::create(['fullname' => 'Khach Xe', 'phone' => '09' . random_int(10000000, 99999999), 'room_id' => $this->room->id]);
        $this->contract = Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $this->tenant->id, 'start_date' => now()->subMonths(2),
            'monthly_price' => 3000000, 'deposit_amount' => 0, 'status' => Contract::STATUS_ACTIVE,
        ]);
    }

    private function h(): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer ' . User::role('super_admin')->first()->createToken('t')->plainTextToken];
    }

    private function vehicle(array $extra = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'building_id' => $this->building->id, 'contract_id' => $this->contract->id, 'tenant_id' => $this->tenant->id,
            'plate_display' => '59A1-123.45', 'vehicle_type' => Vehicle::TYPE_MOTORBIKE, 'name' => 'Honda Vision',
            'status' => Vehicle::STATUS_ACTIVE, 'start_date' => now()->subMonths(3)->toDateString(),
        ], $extra));
    }

    // LỖI THẬT đã gặp: mọi hàm nghiệp vụ xe dùng Vehicle::withoutGlobalScopes() (bỏ HẾT global scope,
    // kể cả SoftDeletingScope) — xe đã xoá mềm ở trang Xe khách thuê (nhân viên bấm "Xoá") vẫn bị tính
    // vào hoá đơn/cảnh báo giới hạn/kiểm tra trùng biển số, dù danh sách không còn hiện nó nữa. Test
    // này khoá chặt: xe đã xoá phải biến mất khỏi CẢ 4 chỗ dùng dữ liệu xe.
    public function test_soft_deleted_vehicle_is_excluded_from_billing_limits_and_duplicate_check(): void
    {
        VehicleRate::create(['building_id' => $this->building->id, 'vehicle_type' => 'motorbike', 'monthly_fee' => 50000, 'max_per_contract' => 1]);
        $vehicle = $this->vehicle(['plate_display' => '64A1-12345']);
        $vehicle->delete(); // xoá mềm, giống nút "Xoá" trên trang Xe khách thuê

        $this->assertTrue($vehicle->trashed());

        // 1) Không còn tính phí.
        $this->assertEmpty(VehicleService::invoiceItems($this->contract, now()->startOfMonth(), now()->endOfMonth()->startOfDay()));

        // 2) Không còn tính vào số lượng xe đang gửi (không cảnh báo vượt giới hạn dù giới hạn = 1).
        $another = $this->vehicle(['plate_display' => '64B1-65432']);
        $this->assertEmpty(VehicleService::limitWarnings($another));
        $this->assertEmpty(VehicleService::contractLimitWarnings($this->contract));

        // 3) Biển số của xe đã xoá được phép dùng lại (tạo xe mới cùng biển số không báo trùng).
        $this->assertFalse(VehicleService::plateInUse($this->building->id, '64A112345'));

        // 4) Không còn xuất hiện trong danh sách xe của khách (Portal).
        $this->assertFalse(VehicleService::vehiclesOf($this->tenant)->contains('id', $vehicle->id));
    }

    public function test_only_motorbike_and_car_types_exist(): void
    {
        $this->assertSame(['motorbike', 'car'], array_keys(Vehicle::TYPES));
    }

    public function test_plate_is_normalized_and_unique_per_building(): void
    {
        $this->assertSame('59A112345', VehicleService::normalizePlate('59a1-123.45'));

        $v = $this->vehicle();
        $this->assertSame('59A112345', $v->plate);
        $this->assertTrue(VehicleService::plateInUse($this->building->id, '59A112345'));
        $this->assertFalse(VehicleService::plateInUse($this->building->id, '59A112345', $v->id));

        $this->postJson('/api/admin/minihouse/vehicles', [
            'contract_id' => $this->contract->id, 'plate' => '59a1 12345', 'vehicle_type' => 'motorbike', 'name' => 'X',
        ], $this->h())->assertStatus(422);
    }

    public function test_rates_api_is_per_building_and_limits_only_warn(): void
    {
        $this->putJson('/api/admin/minihouse/vehicle-rates', [
            'building_id' => $this->building->id,
            'rates'       => ['motorbike' => ['monthly_fee' => 100000, 'max_per_contract' => 1, 'capacity' => 5]],
        ], $this->h())->assertOk();

        $this->getJson("/api/admin/minihouse/vehicle-rates?building_id={$this->building->id}", $this->h())
            ->assertOk()
            ->assertJsonPath('data.0.vehicle_type', 'motorbike')
            ->assertJsonPath('data.0.monthly_fee', 100000)
            ->assertJsonPath('data.0.max_per_contract', 1);

        $this->postJson('/api/admin/minihouse/vehicles', ['contract_id' => $this->contract->id, 'plate' => '59A1-000.01', 'vehicle_type' => 'motorbike', 'name' => 'Xe 1'], $this->h())
            ->assertStatus(201)->assertJsonPath('warnings', []);

        // Xe thứ 2 vượt 1 xe/hợp đồng: VẪN tạo được (201), chỉ có cảnh báo.
        $second = $this->postJson('/api/admin/minihouse/vehicles', ['contract_id' => $this->contract->id, 'plate' => '59A1-000.02', 'vehicle_type' => 'motorbike', 'name' => 'Xe 2'], $this->h());
        $second->assertStatus(201);
        $this->assertNotEmpty($second->json('warnings'));
        $this->assertSame(100000.0, (float) $second->json('data.effective_fee'));

        // Loại xe khác chưa có bảng giá → phí 0, không cảnh báo.
        $car = $this->postJson('/api/admin/minihouse/vehicles', ['contract_id' => $this->contract->id, 'plate' => '30G-999.99', 'vehicle_type' => 'car', 'name' => 'Vios'], $this->h());
        $car->assertStatus(201)->assertJsonPath('warnings', []);
        $this->assertSame(0.0, (float) $car->json('data.effective_fee'));
    }

    public function test_vehicle_fee_is_added_to_generated_invoice_as_line_items(): void
    {
        VehicleRate::create(['building_id' => $this->building->id, 'vehicle_type' => 'motorbike', 'monthly_fee' => 100000]);
        VehicleRate::create(['building_id' => $this->building->id, 'vehicle_type' => 'car', 'monthly_fee' => 1200000]);
        $this->vehicle(['plate_display' => '59A1-111.11']);
        $this->vehicle(['plate_display' => '30G-222.22', 'vehicle_type' => 'car', 'name' => 'Vios']);
        $this->vehicle(['plate_display' => '59A1-333.33', 'status' => Vehicle::STATUS_PENDING]); // chờ duyệt: KHÔNG tính

        $invoice = InvoiceGenerationService::buildInvoiceForContract($this->contract, now()->startOfMonth(), now()->endOfMonth()->startOfDay());

        $items = $invoice->items()->whereNull('surcharge_id')->get();
        $this->assertCount(2, $items);
        $this->assertEquals(1300000.0, (float) $invoice->service_amount);
        $this->assertEquals(3000000 + 1300000, (float) $invoice->total_amount);
        $this->assertTrue($items->contains(fn ($i) => str_contains($i->name, '59A1-111.11') && (float) $i->amount === 100000.0));
    }

    // Phí gửi xe TRỌN GÓI theo loại xe — 2 xe máy cùng lúc vẫn chỉ trả 1 lần phí xe máy/tháng, không
    // nhân đôi theo số xe (phát hiện thật: người dùng thêm 2 xe máy, hoá đơn ra 2 dòng 50.000đ cộng
    // dồn thành 100.000đ, trong khi mong đợi vẫn 50.000đ vì "Tối đa mỗi hợp đồng" chỉ là giới hạn số
    // xe, không phải bậc giá).
    public function test_multiple_vehicles_of_the_same_type_are_billed_once_not_per_vehicle(): void
    {
        VehicleRate::create(['building_id' => $this->building->id, 'vehicle_type' => 'motorbike', 'monthly_fee' => 50000, 'max_per_contract' => 2]);
        $this->vehicle(['plate_display' => '64A1-12345', 'name' => 'Vario 160']);
        $this->vehicle(['plate_display' => '64B1-65432', 'name' => 'AirBlade']);

        $items = VehicleService::invoiceItems($this->contract, now()->startOfMonth(), now()->endOfMonth()->startOfDay());

        $this->assertCount(1, $items);
        $this->assertEquals(50000.0, $items[0]['amount']);
        $this->assertStringContainsString('64A1-12345', $items[0]['name']);
        $this->assertStringContainsString('64B1-65432', $items[0]['name']);

        $invoice = InvoiceGenerationService::buildInvoiceForContract($this->contract, now()->startOfMonth(), now()->endOfMonth()->startOfDay());
        $this->assertEquals(50000.0, (float) $invoice->service_amount);
    }

    // Xe thứ 2 thêm giữa chu kỳ: loại xe đã "có mặt" từ đầu chu kỳ (nhờ xe thứ nhất) nên vẫn tính đủ
    // 1 tháng, không prorate theo ngày của xe thêm sau.
    public function test_second_vehicle_of_same_type_added_mid_cycle_does_not_reduce_or_duplicate_fee(): void
    {
        VehicleRate::create(['building_id' => $this->building->id, 'vehicle_type' => 'motorbike', 'monthly_fee' => 50000]);
        $start = now()->startOfMonth();
        $end   = $start->copy()->addDays(29);
        $this->vehicle(['plate_display' => '64A1-12345', 'start_date' => $start->toDateString()]);
        $this->vehicle(['plate_display' => '64B1-65432', 'start_date' => $start->copy()->addDays(20)->toDateString()]);

        $items = VehicleService::invoiceItems($this->contract, $start, $end);

        $this->assertCount(1, $items);
        $this->assertEquals(50000.0, $items[0]['amount']);
    }

    public function test_vehicle_started_mid_cycle_is_prorated(): void
    {
        VehicleRate::create(['building_id' => $this->building->id, 'vehicle_type' => 'motorbike', 'monthly_fee' => 300000]);
        $start = now()->startOfMonth();
        $end   = $start->copy()->addDays(29); // chu kỳ đầy đủ 30 ngày
        $this->vehicle(['start_date' => $start->copy()->addDays(20)->toDateString()]); // gửi 10 ngày cuối

        $items = VehicleService::invoiceItems($this->contract, $start, $end);

        $this->assertCount(1, $items);
        $this->assertEquals(100000.0, $items[0]['amount']);
    }

    public function test_tenant_declares_vehicle_staff_approves_and_it_starts_billing(): void
    {
        VehicleRate::create(['building_id' => $this->building->id, 'vehicle_type' => 'motorbike', 'monthly_fee' => 100000]);

        [$vehicle, $error] = VehicleService::declareForTenant($this->tenant, ['plate' => '59-x1 999.99', 'vehicle_type' => 'motorbike', 'name' => 'Vision']);

        $this->assertNull($error);
        $this->assertSame(Vehicle::STATUS_PENDING, $vehicle->status);
        $this->assertSame('tenant', $vehicle->requested_by);
        $this->assertSame('Vision', $vehicle->name);
        $this->assertEmpty(VehicleService::invoiceItems($this->contract, now()->startOfMonth(), now()->endOfMonth()->startOfDay()));

        // Trùng biển số khi đang chờ duyệt → từ chối khai báo.
        [$dup, $dupError] = VehicleService::declareForTenant($this->tenant, ['plate' => '59-X1-999.99', 'vehicle_type' => 'motorbike', 'name' => 'Vision 2']);
        $this->assertNull($dup);
        $this->assertNotNull($dupError);

        $this->postJson("/api/admin/minihouse/vehicles/{$vehicle->id}/approve", [], $this->h())
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertCount(1, VehicleService::invoiceItems($this->contract, now()->startOfMonth(), now()->endOfMonth()->startOfDay()));

        // Duyệt lần 2 (đã active) → 422.
        $this->postJson("/api/admin/minihouse/vehicles/{$vehicle->id}/approve", [], $this->h())->assertStatus(422);
    }

    public function test_reject_requires_reason_and_deactivate_stops_billing(): void
    {
        [$pending] = VehicleService::declareForTenant($this->tenant, ['plate' => '11A-000.11', 'vehicle_type' => 'motorbike', 'name' => 'Xe đạp điện']);

        $this->postJson("/api/admin/minihouse/vehicles/{$pending->id}/reject", [], $this->h())->assertStatus(422);
        $this->postJson("/api/admin/minihouse/vehicles/{$pending->id}/reject", ['reason' => 'Ảnh không rõ'], $this->h())
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reject_reason', 'Ảnh không rõ');

        $active = $this->vehicle(['plate_display' => '22B-000.22']);
        $this->postJson("/api/admin/minihouse/vehicles/{$active->id}/deactivate", [], $this->h())
            ->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    public function test_vehicles_are_deactivated_when_contract_ends(): void
    {
        $active    = $this->vehicle(['plate_display' => '33C-000.33']);
        [$pending] = VehicleService::declareForTenant($this->tenant, ['plate' => '44D-000.44', 'vehicle_type' => 'motorbike', 'name' => 'Xe 2']);

        $this->contract->update(['status' => Contract::STATUS_EXPIRED]);

        $this->assertSame(Vehicle::STATUS_INACTIVE, $active->fresh()->status);
        $this->assertNotNull($active->fresh()->end_date);
        $this->assertSame(Vehicle::STATUS_REJECTED, $pending->fresh()->status);
    }

    public function test_lookup_by_plate_and_building_permission(): void
    {
        $v = $this->vehicle(['plate_display' => '77E-123.45']);

        $this->getJson('/api/admin/minihouse/vehicles/lookup?plate=77e12345', $this->h())
            ->assertOk()->assertJsonPath('data.0.id', $v->id)->assertJsonPath('data.0.room_code', $this->room->code);

        $this->getJson('/api/admin/minihouse/vehicles?search=77E', $this->h())->assertOk()->assertJsonFragment(['id' => $v->id]);
    }

    public function test_portal_api_tenant_sees_only_own_vehicles_and_can_cancel_pending(): void
    {
        $mine = $this->vehicle(['plate_display' => '55F-000.55']);

        $otherTenant   = Tenant::create(['fullname' => 'Khac', 'phone' => '09' . random_int(10000000, 99999999), 'room_id' => $this->room->id]);
        $otherContract = Contract::create([
            'room_id' => Room::create(['building_id' => $this->building->id, 'code' => 'Y-' . random_int(100, 999), 'price' => 1, 'status' => Room::STATUS_RENTED])->id,
            'tenant_id' => $otherTenant->id, 'start_date' => now()->subMonth(), 'monthly_price' => 1, 'deposit_amount' => 0, 'status' => Contract::STATUS_ACTIVE,
        ]);
        $theirs = $this->vehicle(['plate_display' => '66G-000.66', 'tenant_id' => $otherTenant->id, 'contract_id' => $otherContract->id]);

        app('auth')->forgetGuards();
        $headers = ['Authorization' => 'Bearer ' . $this->tenant->createToken('t')->plainTextToken];

        $this->getJson('/api/minihouse/portal/vehicles', $headers)
            ->assertOk()->assertJsonFragment(['id' => $mine->id])->assertJsonMissing(['id' => $theirs->id]);

        $created = $this->postJson('/api/minihouse/portal/vehicles', ['plate' => '88H-000.88', 'vehicle_type' => 'motorbike', 'name' => 'Xe đạp điện'], $headers)
            ->assertStatus(201)->assertJsonPath('data.status', 'pending');

        $this->deleteJson('/api/minihouse/portal/vehicles/' . $created->json('data.id'), [], $headers)->assertOk();
        // Không huỷ được xe đã duyệt / xe của người khác.
        $this->deleteJson('/api/minihouse/portal/vehicles/' . $mine->id, [], $headers)->assertStatus(404);
        $this->deleteJson('/api/minihouse/portal/vehicles/' . $theirs->id, [], $headers)->assertStatus(404);
    }

    public function test_portal_web_and_admin_pages_render(): void
    {
        $this->vehicle(['plate_display' => '99K-000.99']);

        auth()->guard('tenant')->login($this->tenant);
        $this->get(route('minihouse.portal.vehicles'))->assertOk()->assertSee('99K-000.99')->assertSee('Khai báo xe mới');

        $this->post(route('minihouse.portal.vehicles.store'), ['plate' => '12L-000.12', 'vehicle_type' => 'motorbike', 'name' => 'Xe mới'])
            ->assertRedirect(route('minihouse.portal.vehicles'));
        $this->assertDatabaseHas('minihouse_vehicles', ['plate' => '12L00012', 'status' => 'pending', 'requested_by' => 'tenant', 'name' => 'Xe mới']);
        auth()->guard('tenant')->logout();

        auth()->shouldUse('web');
        $this->actingAs(User::role('super_admin')->first(), 'web');
        $this->get('/minihouse/admin/vehicles')->assertOk()->assertSee('99K-000.99');
        $this->get('/minihouse/admin/vehicles/create')->assertOk();
        $this->get('/minihouse/admin/manage-vehicle-rates')->assertOk()->assertSee('Bảng giá gửi xe');
    }

    // Tab "Phương tiện" trên trang Chỉnh sửa hợp đồng: thêm xe trực tiếp, đang gửi ngay, tự dò được
    // building_id/tenant_id của hợp đồng, và cảnh báo vượt giới hạn (không chặn) sau khi lưu.
    public function test_vehicles_tab_on_contract_edit_page_can_add_a_vehicle(): void
    {
        VehicleRate::create(['building_id' => $this->building->id, 'vehicle_type' => 'motorbike', 'monthly_fee' => 100000, 'max_per_contract' => 1]);

        auth()->shouldUse('web');
        Filament::setCurrentPanel(Filament::getPanel('minihouse-admin'));
        $this->actingAs(User::role('super_admin')->first(), 'web');

        Livewire::test(EditContract::class, ['record' => $this->contract->id])
            ->fillForm([
                'vehicles' => [
                    'new-1' => ['plate_display' => '68A-111.11', 'vehicle_type' => 'motorbike', 'name' => 'Air Blade', 'status' => 'active'],
                    'new-2' => ['plate_display' => '68A-222.22', 'vehicle_type' => 'motorbike', 'name' => 'Wave', 'status' => 'active'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $vehicles = Vehicle::withoutGlobalScopes()->where('contract_id', $this->contract->id)->get();
        $this->assertCount(2, $vehicles);
        $this->assertTrue($vehicles->every(fn (Vehicle $v) => $v->building_id === $this->building->id && $v->tenant_id === $this->tenant->id));
        // Cùng loại xe máy -> tính phí gộp 1 dòng duy nhất (xem test_multiple_vehicles_of_the_same_type...).
        $this->assertCount(1, VehicleService::invoiceItems($this->contract, now()->startOfMonth(), now()->endOfMonth()->startOfDay()));
    }

    // Phát hiện thật: mở lại trang Chỉnh sửa hợp đồng (Livewire mount MỚI, không phải cùng phiên vừa
    // lưu) rồi bấm "Thêm phương tiện" gõ LẠI đúng biển số đã có sẵn trên CHÍNH hợp đồng này -> tạo ra
    // 1 dòng Vehicle trùng biển số, khiến tên dòng phí gửi xe trên hoá đơn liệt kê biển số lặp lại.
    // Validate phải chặn ngay, không cho lưu thêm bản trùng.
    public function test_re_adding_the_same_plate_already_on_this_contract_is_rejected(): void
    {
        auth()->shouldUse('web');
        Filament::setCurrentPanel(Filament::getPanel('minihouse-admin'));
        $this->actingAs(User::role('super_admin')->first(), 'web');

        // Đã có sẵn 1 xe trên hợp đồng (mô phỏng lần lưu trước đó).
        $this->vehicle(['plate_display' => '64A1-12345']);

        // Mount MỚI (không liên quan phiên Livewire đã lưu ở trên, giống mở lại trang) — item đã có
        // sẵn được Filament tự hydrate; chỉ thêm 1 dòng MỚI trùng biển số qua "Thêm phương tiện".
        $test     = Livewire::test(EditContract::class, ['record' => $this->contract->id]);
        $existing = $test->get('data.vehicles');
        $existing['new-1'] = ['plate_display' => '64a1 12345', 'vehicle_type' => 'motorbike', 'name' => 'Trùng biển số', 'status' => 'active'];

        $test->set('data.vehicles', $existing)
            ->call('save')
            ->assertHasFormErrors();

        $this->assertSame(1, Vehicle::withoutGlobalScopes()->where('contract_id', $this->contract->id)->count());
    }

    // Sửa lại thông tin của CHÍNH xe đã lưu (không đổi biển số) vẫn lưu bình thường — không tự báo
    // "trùng biển số" với chính nó.
    public function test_editing_an_existing_vehicle_without_changing_plate_still_saves(): void
    {
        auth()->shouldUse('web');
        Filament::setCurrentPanel(Filament::getPanel('minihouse-admin'));
        $this->actingAs(User::role('super_admin')->first(), 'web');

        $existing = $this->vehicle(['plate_display' => '64A1-12345', 'name' => 'Vario 160']);

        $test = Livewire::test(EditContract::class, ['record' => $this->contract->id]);
        // Sửa ĐÚNG field lồng trong item Repeater đã được hydrate sẵn từ DB lúc mount (giữ nguyên khoá
        // nội bộ Filament tự sinh cho bản ghi này) — không tự chế khoá mảng, tránh Filament hiểu nhầm
        // đây là 1 dòng MỚI khác hẳn dòng đã có.
        $itemKey = array_key_first($test->get('data.vehicles'));
        $test->set("data.vehicles.{$itemKey}.name", 'Vario 160 (đổi tên)')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Vehicle::withoutGlobalScopes()->where('contract_id', $this->contract->id)->count());
        $this->assertSame('Vario 160 (đổi tên)', $existing->fresh()->name);
    }

    // Ảnh giấy tờ xe (cà-vẹt/đăng ký xe) — tuỳ chọn, upload được lúc tạo/sửa qua API admin, khách
    // thuê cũng tự đính kèm được lúc khai báo trên Portal.
    public function test_document_photo_can_be_uploaded_via_admin_api_and_shown_via_url(): void
    {
        Storage::fake('public');

        $created = $this->postJson('/api/admin/minihouse/vehicles', [
            'contract_id' => $this->contract->id, 'plate' => '59A1-777.77', 'vehicle_type' => 'motorbike', 'name' => 'Vision',
            'document_photo' => UploadedFile::fake()->image('cavet.jpg'),
        ], $this->h());

        $created->assertStatus(201);
        $this->assertNotNull($created->json('data.document_photo_url'));

        $vehicle = Vehicle::withoutGlobalScopes()->find($created->json('data.id'));
        Storage::disk('public')->assertExists($vehicle->document_photo);

        // Sửa lại ảnh khác — ảnh cũ bị xoá.
        $oldPath = $vehicle->document_photo;
        $this->putJson("/api/admin/minihouse/vehicles/{$vehicle->id}", [
            'document_photo' => UploadedFile::fake()->image('cavet-moi.jpg'),
        ], $this->h())->assertOk();

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($vehicle->fresh()->document_photo);
    }

    public function test_tenant_can_attach_document_photo_when_declaring_vehicle_via_portal(): void
    {
        Storage::fake('public');

        app('auth')->forgetGuards();
        $headers = ['Authorization' => 'Bearer ' . $this->tenant->createToken('t')->plainTextToken];

        $created = $this->postJson('/api/minihouse/portal/vehicles', [
            'plate' => '59A1-888.88', 'vehicle_type' => 'motorbike', 'name' => 'Wave',
            'document_photo' => UploadedFile::fake()->image('giay-to.jpg'),
        ], $headers)->assertStatus(201);

        $this->assertNotNull($created->json('data.document_photo_url'));

        $vehicle = Vehicle::withoutGlobalScopes()->find($created->json('data.id'));
        Storage::disk('public')->assertExists($vehicle->document_photo);
    }
}
