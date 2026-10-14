<?php

namespace Tests\Feature\Api\Admin;

use App\Filament\Pages\PartnerSampleData;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Product\App\Models\PriceTemplate;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Tests\Concerns\CleansUpTags;
use Tests\TestCase;

// Trang quản trị → "Dữ liệu mẫu": super_admin chọn đối tác + chi nhánh, sinh ngẫu nhiên khung giờ,
// bảng giá mẫu, tiện ích, dịch vụ, phòng — chạy lại không sinh trùng.
class PartnerSampleDataTest extends TestCase
{
    use CleansUpTags;
    use DatabaseTransactions;

    private object $branch;

    protected function tearDown(): void
    {
        $this->cleanUpTags();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTags();

        $this->actingAs(User::role('super_admin')->first());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->branch = DB::table('categories as c')
            ->join('partners as p', 'p.id', '=', 'c.partner_id')
            ->whereNull('c.parent_id')->where('c.category_type', 'product')->whereNull('c.deleted_at')
            ->where('p.partner_type', 'homestay')
            ->select('c.id', 'c.partner_id')
            ->first();
    }

    private function generate(array $override = [])
    {
        return Livewire::test(PartnerSampleData::class)
            ->fillForm(array_merge([
                'partner_id' => $this->branch->partner_id,
                'branch_id'  => $this->branch->id,
                'time_slots' => 4, 'templates' => 2, 'tags' => 5, 'services' => 4, 'rooms' => 3,
            ], $override))
            ->call('generate');
    }

    public function test_partner_and_branch_are_required(): void
    {
        Livewire::test(PartnerSampleData::class)
            ->fillForm(['partner_id' => null, 'branch_id' => null])
            ->call('generate')
            ->assertHasFormErrors(['partner_id' => 'required', 'branch_id' => 'required']);
    }

    public function test_branch_of_another_partner_is_rejected(): void
    {
        $other = DB::table('partners')->where('id', '!=', $this->branch->partner_id)->value('id');

        $this->generate(['partner_id' => $other, 'branch_id' => $this->branch->id])->assertNotified('Không sinh được dữ liệu mẫu');
        $this->assertSame(0, PriceTemplate::where('partner_id', $other)->where('note', 'Dữ liệu mẫu')->count());
    }

    public function test_generates_data_and_never_duplicates_on_rerun(): void
    {
        $partner = $this->branch->partner_id;

        $this->generate()->assertHasNoFormErrors();
        $this->generate()->assertHasNoFormErrors();

        // Không trùng giờ trong khung giờ riêng, không trùng tên mẫu/tiện ích/dịch vụ/phòng.
        $slots = TimeSlot::where('partner_id', $partner)->get()->map(fn ($t) => substr($t->start_time, 0, 5) . '-' . substr($t->end_time, 0, 5));
        $this->assertSame($slots->count(), $slots->unique()->count());
        $this->assertGreaterThanOrEqual(8, $slots->count());

        $names = PriceTemplate::where('partner_id', $partner)->pluck('name');
        $this->assertSame($names->count(), $names->unique()->count());

        $tags = Tag::where('partner_id', $partner)->get()->map(fn ($t) => mb_strtolower($t->name));
        $this->assertSame($tags->count(), $tags->unique()->count());

        $services = AdditionService::withoutGlobalScopes()->where('partner_id', $partner)->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $this->assertSame($services->count(), $services->unique()->count());

        $rooms = Product::withoutGlobalScopes()->where('partner_id', $partner)->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $this->assertSame($rooms->count(), $rooms->unique()->count());

        // Phòng mẫu mới có giá khung giờ và dịch vụ của chi nhánh.
        $room = Product::withoutGlobalScopes()->where('partner_id', $partner)->latest('created_at')->first();
        $this->assertTrue(RoomTimeSlot::where('room_id', $room->id)->whereNull('date')->whereNotNull('price')->exists());
        $this->assertTrue($room->additionalServices()->exists());
    }

    public function test_purge_button_removes_sample_data_of_selected_partner(): void
    {
        $this->generate()->assertHasNoFormErrors();
        $this->assertGreaterThan(0, DB::table('sample_data_records')->where('partner_id', $this->branch->partner_id)->count());

        Livewire::test(PartnerSampleData::class)
            ->fillForm(['partner_id' => $this->branch->partner_id])
            ->call('purge')
            ->assertNotified('Đã xoá dữ liệu mẫu');

        $this->assertSame(0, DB::table('sample_data_records')->where('partner_id', $this->branch->partner_id)->count());
    }
}
