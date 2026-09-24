<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Modules\Minihouse\App\Filament\Resources\RoomResource\Pages\CreateRoom;
use Modules\Minihouse\App\Filament\Resources\RoomResource\Pages\EditRoom;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Form Thêm/Sửa Phòng bố cục 2 cột giống Home + API tạo/sửa phòng nhận thêm các cột có sẵn trên
// products (địa chỉ, vĩ độ/kinh độ, link Google Maps, hotline, video, slug).
class RoomFormMapVideoFieldsTest extends TestCase
{
    use DatabaseTransactions;

    private function building(): Building
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);

        return Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
    }

    public function test_api_persists_and_returns_map_and_video_fields(): void
    {
        $building = $this->building();
        $token    = User::role('super_admin')->first()->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/rooms', [
                'building_id' => $building->id,
                'code'        => 'R-' . uniqid(),
                'price'       => 1000000,
                'address'     => '12 Test',
                'latitude'    => 10.7769,
                'longitude'   => 106.7009,
                'map_url'     => 'https://maps.app.goo.gl/abc',
                'hotline'     => '0909123456',
                'video'       => ['url' => 'https://youtube.com/watch?v=1', 'ratio' => '9:16', 'title' => 'T'],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.address', '12 Test')
            ->assertJsonPath('data.map_url', 'https://maps.app.goo.gl/abc')
            ->assertJsonPath('data.hotline', '0909123456')
            ->assertJsonPath('data.video.ratio', '9:16');

        $this->assertStringStartsWith('mh-', $response->json('data.slug'));
    }

    public function test_api_rejects_invalid_map_url_and_video_ratio(): void
    {
        $building = $this->building();
        $token    = User::role('super_admin')->first()->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/rooms', [
                'building_id' => $building->id,
                'code'        => 'R-' . uniqid(),
                'price'       => 1000000,
                'map_url'     => 'not-a-url',
                'video'       => ['ratio' => '1:1'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['map_url', 'video.ratio']);
    }

    public function test_filament_create_form_has_new_fields_and_no_removed_ones(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('minihouse-admin'));
        $this->actingAs(User::role('super_admin')->first());

        $fields = collect(Livewire::test(CreateRoom::class)->assertFormExists()->instance()->form->getFlatFields())->keys();

        foreach (['building_id', 'code', 'slug', 'address', 'latitude', 'longitude', 'map_url', 'hotline', 'setting_video_room.url', 'setting_video_room.ratio', 'setting_video_room.title', 'Ảnh bìa', 'Thư viện', 'amenities'] as $expected) {
            $this->assertTrue($fields->contains($expected), "Thiếu field {$expected} trên form Phòng");
        }

        foreach (['wifi', 'home_code', 'styles', 'room_type_id'] as $removed) {
            $this->assertFalse($fields->contains($removed), "Field {$removed} không được hiện trên form Phòng MiniHouse");
        }
    }

    public function test_room_model_mass_assigns_new_columns(): void
    {
        $room = Room::create([
            'building_id' => $this->building()->id,
            'code'        => 'R-' . uniqid(),
            'price'       => 1000000,
            'latitude'    => 21.0285,
            'longitude'   => 105.8542,
            'map_url'     => 'https://maps.app.goo.gl/x',
        ]);

        $fresh = Room::withoutGlobalScopes()->find($room->id);

        $this->assertEquals(21.0285, (float) $fresh->latitude);
        $this->assertSame('https://maps.app.goo.gl/x', $fresh->map_url);
    }

    public function test_filament_edit_form_renders_for_existing_room_without_media(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('minihouse-admin'));
        $this->actingAs(User::role('super_admin')->first());

        $room = Room::create([
            'building_id' => $this->building()->id,
            'code'        => 'R-' . uniqid(),
            'price'       => 1000000,
            'photos'      => ['minihouse/rooms/legacy.jpg'],
        ]);

        Livewire::test(EditRoom::class, ['record' => $room->getKey()])->assertFormExists()->assertHasNoFormErrors();

        $this->assertSame(['minihouse/rooms/legacy.jpg'], Room::withoutGlobalScopes()->find($room->id)->photos);
    }
}
