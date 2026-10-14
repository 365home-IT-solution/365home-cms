<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use App\Support\ActivePartner;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Product\App\Filament\Resources\PriceTemplateResource\Pages\CreatePriceTemplate;
use Modules\Product\App\Filament\Resources\PriceTemplateResource\Pages\ListPriceTemplates;
use Modules\Product\App\Models\PriceTemplate;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Tests\Concerns\CleansUpTags;
use Modules\Product\App\Filament\Resources\PriceTemplateResource\Pages\EditPriceTemplate;
use Tests\TestCase;

// Trang quản trị → "Bảng giá mẫu": super_admin tạo mẫu hệ thống, đối tác tách bản riêng rồi áp
// xuống phòng của mình.
class PriceTemplateResourceTest extends TestCase
{
    use CleansUpTags;
    use DatabaseTransactions;

    private User $admin;

    protected function tearDown(): void
    {
        $this->cleanUpTags();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTags();

        $this->admin = User::role('super_admin')->first();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function systemSlotIds(int $n): array
    {
        return TimeSlot::whereNull('partner_id')->where(fn ($q) => $q->whereNull('type')->orWhere('type', 'time'))
            ->orderBy('id')->limit($n)->pluck('id')->all();
    }

    public function test_super_admin_creates_system_template_and_partner_forks_then_applies(): void
    {
        [$s1, $s2] = $this->systemSlotIds(2);

        $undo = Repeater::fake();
        Livewire::test(CreatePriceTemplate::class)
            ->fillForm([
                'name'      => 'Mẫu admin web',
                'is_active' => true,
                'slots'     => [
                    ['timeslot_id' => $s1, 'price' => 100000, 'over_night' => false],
                    ['timeslot_id' => $s2, 'price' => 200000, 'over_night' => false],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $undo();

        $system = PriceTemplate::where('name', 'Mẫu admin web')->firstOrFail();
        $this->assertNull($system->partner_id);
        $this->assertSame(2, $system->slots()->count());

        $room = Product::withoutGlobalScopes()->where('styles', 1)->whereNotNull('partner_id')
            ->whereHas('categories')->firstOrFail();
        ActivePartner::apply($this->admin, $room->partner_id);

        // Đối tác: mẫu hệ thống không sửa trực tiếp được, chỉ "Sửa thành bản riêng".
        Livewire::test(ListPriceTemplates::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$system])
            ->assertTableActionHidden('edit', $system)
            ->assertTableActionHidden('delete', $system)
            ->callTableAction('fork', $system);

        $fork = PriceTemplate::where('source_template_id', $system->id)->where('partner_id', $room->partner_id)->firstOrFail();
        $this->assertSame(2, $fork->slots()->count());

        Livewire::test(ListPriceTemplates::class)
            ->assertCanNotSeeTableRecords([$system])
            ->assertTableActionVisible('edit', $fork)
            ->callTableAction('apply', $fork, data: ['room_ids' => [$room->id], 'mode' => 'replace'])
            ->assertHasNoTableActionErrors();

        $prices = RoomTimeSlot::where('room_id', $room->id)->whereNull('date')
            ->whereHas('timeSlot', fn ($q) => $q->where(fn ($q2) => $q2->whereNull('type')->orWhere('type', '!=', 'date')))
            ->pluck('price', 'timeslot_id')->map(fn ($p) => (int) $p)->all();
        ksort($prices);
        $this->assertSame([$s1 => 100000, $s2 => 200000], $prices);
    }

    public function test_partner_only_sees_system_and_own_time_slots_in_options(): void
    {
        $partners = DB::table('partners')->limit(2)->pluck('id');
        $other    = TimeSlot::create(['partner_id' => $partners[1], 'start_time' => '04:11', 'end_time' => '04:59', 'label' => 'khác', 'type' => 'time']);

        ActivePartner::apply($this->admin, $partners[0]);

        $this->assertNotContains($other->id, TimeSlot::forCurrentAdmin()->pluck('id')->all());
        $this->assertContains($this->systemSlotIds(1)[0], TimeSlot::forCurrentAdmin()->pluck('id')->all());
    }

    // Lỗi 500 cũ: ô chọn khung giờ trong Repeater nhận PriceTemplateSlot thay vì PriceTemplate.
    public function test_edit_page_renders_and_saves_slot_prices(): void
    {
        [$s1, $s2] = $this->systemSlotIds(2);

        $template = PriceTemplate::create(['partner_id' => null, 'name' => 'Mẫu sửa web', 'is_active' => true]);
        app(\App\Services\PriceTemplateService::class)->replaceSlots($template, [
            ['timeslot_id' => $s1, 'price' => 100000],
            ['timeslot_id' => $s2, 'price' => 200000],
        ]);

        $page = Livewire::test(EditPriceTemplate::class, ['record' => $template->id])->assertSuccessful();

        $slots = $page->get('data.slots');
        $first = array_key_first($slots);
        $slots[$first]['price'] = 150000;

        $page->fillForm(['name' => 'Mẫu đã sửa', 'slots' => $slots])->call('save')->assertHasNoFormErrors();

        $this->assertSame('Mẫu đã sửa', $template->fresh()->name);
        $this->assertSame(150000, (int) $template->slots()->where('timeslot_id', $s1)->value('price'));
    }
}
