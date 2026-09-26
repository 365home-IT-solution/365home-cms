<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Models\WarehouseItem;
use Modules\Minihouse\App\Support\HomestayBridge;
use Tests\TestCase;

// API kho vật tư MiniHouse — mirror /api/admin/warehouse/* của Home nhưng lọc theo building_id, xem
// App\Http\Controllers\Api\Admin\Minihouse\Warehouse*Controller. Khoá lại đúng phần cốt lõi: số dư
// tồn kho (running balance) tăng/giảm đúng qua từng loại phiếu, 2 guard nghiệp vụ (không xuất/hoàn
// vượt khả dụng), và phạm vi quyền theo Toà nhà.
class WarehouseApiTest extends TestCase
{
    use DatabaseTransactions;

    private function makeBuilding(): Category
    {
        $name = 'Toa nha kho ' . uniqid();

        return Category::create([
            'name'          => $name,
            'slug'          => Str::slug($name) . '-' . uniqid(),
            'category_type' => 'product',
            'parent_id'     => null,
            'partner_id'    => HomestayBridge::PARTNER_ID,
            'status'        => true,
        ]);
    }

    private function token(): string
    {
        return User::role('super_admin')->first()->createToken('t')->plainTextToken;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token()];
    }

    public function test_category_and_unit_crud(): void
    {
        $headers = $this->auth();

        $category = $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-categories', ['name' => 'Do dung phong'])
            ->assertStatus(201)
            ->json('data');

        $this->withHeaders($headers)
            ->putJson("/api/admin/minihouse/warehouse-categories/{$category['id']}", ['name' => 'Do dung phong sua'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Do dung phong sua');

        $unit = $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-units', ['name' => 'Cai'])
            ->assertStatus(201)
            ->json('data');

        $this->withHeaders($headers)
            ->getJson('/api/admin/minihouse/warehouse-units')
            ->assertOk()
            ->assertJsonFragment(['id' => $unit['id']]);

        $this->withHeaders($headers)
            ->deleteJson("/api/admin/minihouse/warehouse-categories/{$category['id']}")
            ->assertOk();
    }

    public function test_item_crud_and_scan_scoped_by_building(): void
    {
        $building = $this->makeBuilding();
        $unit = $this->withHeaders($this->auth())->postJson('/api/admin/minihouse/warehouse-units', ['name' => 'Cai'])->json('data');
        $headers = $this->auth();

        $create = $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-items', [
                'building_id'       => $building->id,
                'name'              => 'Bong den',
                'sku'               => 'BD-' . uniqid(),
                'warehouse_unit_id' => $unit['id'],
                'min_quantity'      => 5,
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame(0.0, (float) $create['quantity']);

        $this->withHeaders($headers)
            ->getJson('/api/admin/minihouse/warehouse-items/scan?code=' . $create['sku'])
            ->assertOk()
            ->assertJsonPath('data.id', $create['id']);

        // "quantity" không sửa được qua API item — chỉ qua phiếu.
        $this->withHeaders($headers)
            ->putJson("/api/admin/minihouse/warehouse-items/{$create['id']}", ['quantity' => 999])
            ->assertOk();
        $this->assertSame(0.0, (float) WarehouseItem::find($create['id'])->quantity);
    }

    public function test_stock_in_increases_quantity_and_stock_out_guards_over_issue(): void
    {
        $building = $this->makeBuilding();
        $headers = $this->auth();

        $item = WarehouseItem::create(['building_id' => $building->id, 'name' => 'Khan tam']);

        $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-stock-ins', [
                'building_id' => $building->id,
                'items'       => [['warehouse_item_id' => $item->id, 'quantity' => 10, 'unit_price' => 5000]],
            ])
            ->assertStatus(201);

        $this->assertSame(10.0, (float) $item->fresh()->quantity);

        // Xuất vượt tồn khả dụng — phải bị chặn 422.
        $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-stock-outs', [
                'building_id' => $building->id,
                'items'       => [['warehouse_item_id' => $item->id, 'quantity' => 999, 'reason' => 'other']],
            ])
            ->assertStatus(422);

        $this->assertSame(10.0, (float) $item->fresh()->quantity);

        $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-stock-outs', [
                'building_id' => $building->id,
                'items'       => [['warehouse_item_id' => $item->id, 'quantity' => 4, 'reason' => 'damaged']],
            ])
            ->assertStatus(201);

        $this->assertSame(6.0, (float) $item->fresh()->quantity);
    }

    public function test_stock_check_adjusts_quantity_to_actual_count(): void
    {
        $building = $this->makeBuilding();
        $headers = $this->auth();

        $item = WarehouseItem::create(['building_id' => $building->id, 'name' => 'Ghe nhua', 'quantity' => 20]);

        $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-stock-checks', [
                'building_id' => $building->id,
                'checked_at'  => now()->toIso8601String(),
                'items'       => [['warehouse_item_id' => $item->id, 'actual_quantity' => 17]],
            ])
            ->assertStatus(201);

        $this->assertSame(17.0, (float) $item->fresh()->quantity);
    }

    public function test_stock_return_tracked_guard_and_untracked_unlimited(): void
    {
        $building = $this->makeBuilding();
        $headers = $this->auth();

        $item = WarehouseItem::create(['building_id' => $building->id, 'name' => 'Xa phong', 'quantity' => 10]);

        $stockOut = $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-stock-outs', [
                'building_id' => $building->id,
                'items'       => [['warehouse_item_id' => $item->id, 'quantity' => 5, 'reason' => 'damaged']],
            ])
            ->json('data');

        $stockOutItemId = $stockOut['items'][0]['id'];
        $this->assertSame(5.0, (float) $item->fresh()->quantity);

        // Hoàn vượt số đã xuất (5) — bị chặn.
        $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-stock-returns', [
                'building_id' => $building->id,
                'items'       => [['warehouse_stock_out_item_id' => $stockOutItemId, 'quantity' => 999]],
            ])
            ->assertStatus(422);

        // Hoàn đúng 1 phần (3) — được phép, tăng tồn.
        $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-stock-returns', [
                'building_id' => $building->id,
                'items'       => [['warehouse_stock_out_item_id' => $stockOutItemId, 'quantity' => 3]],
            ])
            ->assertStatus(201);

        $this->assertSame(8.0, (float) $item->fresh()->quantity);

        // Hoàn KHÔNG TRUY VẾT (vật tư dư tìm thấy) — không giới hạn.
        $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-stock-returns', [
                'building_id' => $building->id,
                'items'       => [['warehouse_item_id' => $item->id, 'quantity' => 100]],
            ])
            ->assertStatus(201);

        $this->assertSame(108.0, (float) $item->fresh()->quantity);
    }

    public function test_cannot_manage_warehouse_outside_permitted_building(): void
    {
        $allowedBuilding = $this->makeBuilding();
        $notAllowedBuilding = $this->makeBuilding();

        $manager = User::role('super_admin')->first()->replicate();
        $manager->email = 'mh-wh-manager-' . uniqid() . '@test.local';
        $manager->password = bcrypt('secret');
        $manager->save();
        $manager->syncRoles([]);
        $manager->givePermissionTo(['view_any_warehouse', 'create_warehouse']);
        $manager->minihouseBuildings()->attach($allowedBuilding->id);

        $token = $manager->createToken('t')->plainTextToken;
        $unit  = \Modules\Minihouse\App\Models\WarehouseUnit::create(['name' => 'Cai ' . uniqid()]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/warehouse-items', [
                'building_id'       => $allowedBuilding->id,
                'name'              => 'Vat tu OK',
                'warehouse_unit_id' => $unit->id,
            ])
            ->assertStatus(201);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/warehouse-items', [
                'building_id'       => $notAllowedBuilding->id,
                'name'              => 'Vat tu khong duoc phep',
                'warehouse_unit_id' => $unit->id,
            ])
            ->assertStatus(403);

        $foreignItem = WarehouseItem::create(['building_id' => $notAllowedBuilding->id, 'name' => 'Vat tu nguoi khac']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson("/api/admin/minihouse/warehouse-items/{$foreignItem->id}")
            ->assertStatus(404);
    }

    public function test_cannot_delete_item_with_stock_history(): void
    {
        $building = $this->makeBuilding();
        $headers = $this->auth();

        $item = WarehouseItem::create(['building_id' => $building->id, 'name' => 'Vat tu co lich su']);

        $this->withHeaders($headers)
            ->postJson('/api/admin/minihouse/warehouse-stock-ins', [
                'building_id' => $building->id,
                'items'       => [['warehouse_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 1000]],
            ])
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->deleteJson("/api/admin/minihouse/warehouse-items/{$item->id}")
            ->assertStatus(409);
    }
}
