<?php

namespace Tests\Feature;

use App\Models\Partner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Modules\BladeThemeV1\Livewire\ProductDetail;
use Modules\Product\App\Models\Product;
use Tests\TestCase;

// Trang chi tiết phòng: đối tác được Super Admin bật "CCCD 2 mặt" (partners.cccd_two_sides) thì khách phải tải thêm
// ảnh mặt còn lại; đối tác không bật vẫn chỉ 1 ảnh mặt có mã QR như cũ.
class ProductDetailCccdTwoSidesTest extends TestCase
{
    use DatabaseTransactions;

    private function room(): Product
    {
        $room = Product::query()->where('is_activated', true)->whereNotNull('partner_id')->whereNotNull('slug')->first();

        if (! $room) {
            $this->markTestSkipped('Không có phòng đang bật nào gắn đối tác.');
        }

        return $room;
    }

    private function setTwoSides(Product $room, bool $enabled): void
    {
        Partner::withoutGlobalScopes()->whereKey($room->partner_id)->update(['cccd_two_sides' => $enabled]);
    }

    public function test_partner_with_two_sides_requires_back_image(): void
    {
        $room = $this->room();
        $this->setTwoSides($room, true);

        $component = Livewire::test(ProductDetail::class, ['slug' => $room->slug])
            ->assertSet('cccdTwoSides', true)
            ->assertSee('Ảnh CCCD — mặt còn lại');

        $this->assertTrue($component->instance()->needsMainCccdBack());
    }

    public function test_partner_without_two_sides_keeps_single_image(): void
    {
        $room = $this->room();
        $this->setTwoSides($room, false);

        $component = Livewire::test(ProductDetail::class, ['slug' => $room->slug])
            ->assertSet('cccdTwoSides', false)
            ->assertDontSee('Ảnh CCCD — mặt còn lại');

        $this->assertFalse($component->instance()->needsMainCccdBack());
    }

    public function test_client_cannot_turn_off_two_sides(): void
    {
        $room = $this->room();
        $this->setTwoSides($room, true);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::test(ProductDetail::class, ['slug' => $room->slug])->set('cccdTwoSides', false);
    }

    public function test_super_admin_toggles_several_partners_at_once(): void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs(\App\Models\User::role('super_admin')->first());

        $partners = collect([
            Partner::create(['name' => 'CCCD 2 mat A ' . uniqid(), 'status' => true]),
            Partner::create(['name' => 'CCCD 2 mat B ' . uniqid(), 'status' => true]),
        ]);

        Livewire::test(\App\Filament\Resources\PartnerResource\Pages\ListPartners::class)
            ->callTableBulkAction('enableCccdTwoSides', $partners);
        $partners->each(fn (Partner $p) => $this->assertTrue((bool) $p->fresh()->cccd_two_sides));

        Livewire::test(\App\Filament\Resources\PartnerResource\Pages\ListPartners::class)
            ->callTableBulkAction('disableCccdTwoSides', $partners);
        $partners->each(fn (Partner $p) => $this->assertFalse((bool) $p->fresh()->cccd_two_sides));
    }
}
