<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Tests\TestCase;

// Thứ tự phòng: Product::displayOrder() (dashboard, app admin) + "Sắp theo số phòng" / kéo thả ở Thiết lập Phòng.
class RoomDisplayOrderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sort_by_room_number(): void
    {
        $names  = ['Đơn Classic 401', 'Đôi Classic 302', 'Phòng gia đình', 'Đơn Classic 001', 'Đôi Deluxe 101', 'Đơn Deluxe 102', 'Đôi Deluxe 201'];
        $rooms  = collect($names)->map(fn ($n) => (new Product())->forceFill(['name' => $n]));
        $sorted = array_map(fn (Product $p) => $p->name, Product::sortByRoomNumber($rooms));

        $this->assertSame(['Đơn Classic 001', 'Đôi Deluxe 101', 'Đơn Deluxe 102', 'Đôi Deluxe 201', 'Đôi Classic 302', 'Đơn Classic 401', 'Phòng gia đình'], $sorted);
    }

    public function test_display_order_and_sort_action(): void
    {
        $partnerId = DB::table('products')->join('partners', 'partners.id', '=', 'products.partner_id')
            ->whereNull('partners.deleted_at')->whereNull('products.deleted_at')
            ->groupBy('products.partner_id')->havingRaw('COUNT(*) >= 3')->value('products.partner_id');
        if (! $partnerId) {
            $this->markTestSkipped('Cần 1 đối tác có từ 3 phòng.');
        }

        $rooms = Product::withoutGlobalScopes()->where('partner_id', $partnerId)->whereNull('deleted_at')->orderBy('id')->get();
        // Đặt tên có số theo thứ tự NGƯỢC với id để thấy rõ khác biệt.
        foreach ($rooms->values() as $i => $room) {
            DB::table('products')->where('id', $room->id)->update(['name' => 'Phòng ' . str_pad((string) (900 - $i), 3, '0', STR_PAD_LEFT), 'sort_order' => 0]);
        }

        // Mọi sort_order = 0 → theo tên (như trước).
        $byName = Product::withoutGlobalScopes()->where('partner_id', $partnerId)->whereNull('deleted_at')->displayOrder()->pluck('name')->all();
        $this->assertSame(collect($byName)->sort(SORT_NATURAL)->values()->all(), $byName);

        // Nút "Sắp theo số phòng" (Super Admin chọn đối tác).
        $this->actingAs(User::role('super_admin')->first());
        \Livewire\Livewire::test(\Modules\Product\App\Filament\Resources\ProductResource\Pages\ListProduct::class)
            ->assertSuccessful()
            ->assertTableActionVisible('sortByRoomNumber')
            ->mountTableAction('sortByRoomNumber')
            ->set('mountedTableActionsData.0.partner_id', $partnerId)
            ->assertSee('Thứ tự sau khi lưu')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $ordered = Product::withoutGlobalScopes()->where('partner_id', $partnerId)->whereNull('deleted_at')->displayOrder()->get(['name', 'sort_order']);
        $this->assertSame(range(1, $ordered->count()), $ordered->pluck('sort_order')->all());
        $this->assertSame(collect($ordered->pluck('name'))->sort(SORT_NATURAL)->values()->all(), $ordered->pluck('name')->all());

        // Kéo thả: đảo 2 phòng đầu → displayOrder theo sort_order chứ không theo tên nữa.
        DB::table('products')->where('partner_id', $partnerId)->where('name', $ordered[0]->name)->update(['sort_order' => 2]);
        DB::table('products')->where('partner_id', $partnerId)->where('name', $ordered[1]->name)->update(['sort_order' => 1]);
        $first = Product::withoutGlobalScopes()->where('partner_id', $partnerId)->whereNull('deleted_at')->displayOrder()->value('name');
        $this->assertSame($ordered[1]->name, $first);
    }

    public function test_room_list_is_reorderable(): void
    {
        $this->actingAs(User::role('super_admin')->first());

        \Livewire\Livewire::test(\Modules\Product\App\Filament\Resources\ProductResource\Pages\ListProduct::class)
            ->call('toggleTableReordering')
            ->assertSet('isTableReordering', true)
            ->assertSuccessful();
    }
}
