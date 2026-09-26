<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Exports\WarehouseItemExport;
use Modules\Minihouse\App\Exports\WarehouseStockInExport;
use Modules\Minihouse\App\Filament\Support\WarehousePrinter;
use Modules\Minihouse\App\Models\WarehouseItem;
use Modules\Minihouse\App\Models\WarehouseStockCheck;
use Modules\Minihouse\App\Models\WarehouseStockIn;
use Modules\Minihouse\App\Models\WarehouseStockOut;
use Modules\Minihouse\App\Models\WarehouseStockReturn;
use Modules\Minihouse\App\Support\HomestayBridge;
use Tests\TestCase;

// Các phần giống Home của kho MiniHouse: in PDF/QR, xuất Excel, trang Sửa (kiểm kê có bàn giao,
// vật tư có sổ kho), API QR + dòng "tồn ban đầu".
class WarehouseFilamentParityTest extends TestCase
{
    use DatabaseTransactions;

    private function building(): Category
    {
        $name = 'Toa nha pt ' . uniqid();

        return Category::create([
            'name' => $name, 'slug' => Str::slug($name) . '-' . uniqid(), 'category_type' => 'product',
            'parent_id' => null, 'partner_id' => HomestayBridge::PARTNER_ID, 'status' => true,
        ]);
    }

    public function test_printing_exports_and_edit_pages_work(): void
    {
        $admin = User::role('super_admin')->first();
        $this->actingAs($admin);
        $b = $this->building();
        $item = WarehouseItem::create(['building_id' => $b->id, 'name' => 'Bong den', 'sku' => 'SKU-' . uniqid(), 'quantity' => 5]);

        $in = WarehouseStockIn::create(['building_id' => $b->id]);
        $in->items()->create(['warehouse_item_id' => $item->id, 'quantity' => 3, 'unit_price' => 1000]);
        $out = WarehouseStockOut::create(['building_id' => $b->id]);
        $out->items()->create(['warehouse_item_id' => $item->id, 'quantity' => 2, 'reason' => 'other']);
        $check = WarehouseStockCheck::create(['building_id' => $b->id, 'checked_at' => now()]);
        $check->items()->create(['warehouse_item_id' => $item->id, 'actual_quantity' => 6]);
        $ret = WarehouseStockReturn::create(['building_id' => $b->id]);
        $ret->items()->create(['warehouse_item_id' => $item->id, 'quantity' => 1]);

        foreach ([WarehousePrinter::stockIn($in), WarehousePrinter::stockOut($out), WarehousePrinter::stockCheck($check), WarehousePrinter::stockReturn($ret)] as $response) {
            ob_start();
            $response->sendContent();
            $pdf = ob_get_clean();
            $this->assertStringStartsWith('%PDF', $pdf);
        }

        foreach ([WarehousePrinter::itemList(WarehouseItem::whereKey($item->id)->get(), false), WarehousePrinter::qrCodes(WarehouseItem::whereKey($item->id)->get(), 1), WarehousePrinter::qrCodes(WarehouseItem::whereKey($item->id)->get(), 4)] as $response) {
            ob_start();
            $response->sendContent();
            $this->assertStringStartsWith('%PDF', ob_get_clean());
        }

        $this->assertNotEmpty(Excel::raw(new WarehouseItemExport(WarehouseItem::withoutGlobalScopes()->where('building_id', $b->id), false), \Maatwebsite\Excel\Excel::XLSX));
        $this->assertNotEmpty(Excel::raw(new WarehouseStockInExport(WarehouseStockIn::withoutGlobalScopes()->where('building_id', $b->id), false), \Maatwebsite\Excel\Excel::XLSX));

        foreach ([
            "/minihouse/admin/warehouse-items/{$item->id}/edit",
            "/minihouse/admin/warehouse-stock-ins/{$in->id}/edit",
            "/minihouse/admin/warehouse-stock-outs/{$out->id}/edit",
            "/minihouse/admin/warehouse-stock-checks/{$check->id}/edit",
            "/minihouse/admin/warehouse-stock-returns/{$ret->id}/edit",
            '/minihouse/admin/warehouse-items',
            '/minihouse/admin/warehouse-stock-ins',
            '/minihouse/admin/warehouse-stock-outs',
            '/minihouse/admin/warehouse-stock-checks',
            '/minihouse/admin/warehouse-stock-returns',
            '/minihouse/admin/warehouse-stock-checks/create',
        ] as $url) {
            $status = $this->get($url)->status();
            $this->assertSame(200, $status, "{$url} -> {$status}");
        }
    }

    public function test_api_qr_and_initial_movement(): void
    {
        $b = $this->building();
        $token = User::role('super_admin')->first()->createToken('t')->plainTextToken;
        $h = ['Authorization' => 'Bearer ' . $token];
        $item = WarehouseItem::create(['building_id' => $b->id, 'name' => 'Xa phong', 'sku' => 'QR-' . uniqid(), 'quantity' => 10]);

        $this->withHeaders($h)->getJson("/api/admin/minihouse/warehouse-items/{$item->id}")
            ->assertOk()->assertJsonStructure(['data' => ['qr_code_base64']]);

        $this->withHeaders($h)->get("/api/admin/minihouse/warehouse-items/{$item->id}/qrcode")
            ->assertOk()->assertHeader('Content-Type', 'image/png');

        $r = $this->withHeaders($h)->getJson("/api/admin/minihouse/warehouse-items/{$item->id}/movements")->assertOk();
        $this->assertSame('initial', $r->json('data.0.type'));
        $this->assertEquals(10, $r->json('data.0.change'));
    }

    public function test_stock_check_handover_confirms_and_flags_discrepancy(): void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('minihouse-admin'));
        $this->actingAs(User::role('super_admin')->first());
        $b = $this->building();
        $item = WarehouseItem::create(['building_id' => $b->id, 'name' => 'Ghe', 'quantity' => 5]);
        $check = WarehouseStockCheck::create(['building_id' => $b->id, 'checked_at' => now()]);
        $line = $check->items()->create(['warehouse_item_id' => $item->id, 'actual_quantity' => 5]);

        \Livewire\Livewire::test(\Modules\Minihouse\App\Filament\Resources\WarehouseStockCheckResource\Pages\EditWarehouseStockCheck::class, ['record' => $check->getKey()])
            ->callAction('confirmHandover', ['handoverLines' => [['check_item_id' => $line->id, 'handover_quantity' => 4]], 'handover_note' => 'lech 1']);

        $this->assertSame(WarehouseStockCheck::HANDOVER_DISCREPANCY, $check->fresh()->handover_status);
        $this->assertEquals(-1, $line->fresh()->handover_difference);
        // Bàn giao KHÔNG điều chỉnh tồn kho.
        $this->assertEquals(5, $item->fresh()->quantity);
    }
}
