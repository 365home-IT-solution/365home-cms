<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\PanoramaHotspot;
use Modules\Minihouse\App\Models\PanoramaScene;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Module "Sơ đồ 360°" — tour ảo CÔNG KHAI (không cần đăng nhập) cho khách xem sơ đồ phòng bằng ảnh
// toàn cảnh (Pannellum), yêu cầu người dùng 2026-09-13. Test này khoá lại: (1) trang tour công khai
// truy cập được không cần auth, (2) điểm vào MẶC ĐỊNH ưu tiên điểm chung (room_id null) thay vì 1
// phòng cụ thể, (3) endpoint JSON chỉ trả về scene/hotspot ĐÃ CÔNG KHAI, không rò rỉ điểm chưa công
// khai hoặc hotspot trỏ tới điểm đã ẩn/xoá.
class PanoramaTourTest extends TestCase
{
    use DatabaseTransactions;

    private function makeBuilding(): Building
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);

        return Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
    }

    public function test_public_tour_page_loads_without_authentication(): void
    {
        $building = $this->makeBuilding();
        PanoramaScene::create([
            'building_id' => $building->id, 'title' => 'Sanh chinh', 'image_path' => 'fake.jpg',
        ]);

        $response = $this->get(route('minihouse.tour.show', $building->id));

        $response->assertOk();
        $response->assertSee($building->name);
    }

    public function test_tour_entry_point_prefers_common_area_scene_over_room_scene(): void
    {
        $building = $this->makeBuilding();
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 1000000, 'status' => Room::STATUS_EMPTY]);

        // Tạo scene PHÒNG trước (sort_order nhỏ hơn) — nếu chỉ sort theo sort_order thì scene này sẽ
        // được chọn làm điểm vào, SAI với kỳ vọng "khách phải bước vào sảnh trước".
        $roomScene = PanoramaScene::create([
            'building_id' => $building->id, 'room_id' => $room->id, 'title' => 'Phong', 'image_path' => 'r.jpg', 'sort_order' => 0,
        ]);
        $lobbyScene = PanoramaScene::create([
            'building_id' => $building->id, 'title' => 'Sanh', 'image_path' => 'l.jpg', 'sort_order' => 1,
        ]);

        $response = $this->get(route('minihouse.tour.show', $building->id));

        $response->assertOk();
        // Điểm vào phải là scene CHUNG (room_id null, "Sanh") — không phải scene PHÒNG dù scene đó
        // có sort_order nhỏ hơn (xem PanoramaTourController::show() — ưu tiên room_id IS NULL trước).
        $response->assertSee("scene_{$lobbyScene->id}'", false);
        $response->assertDontSee("scene_{$roomScene->id}'", false);
    }

    public function test_data_endpoint_excludes_unpublished_scenes(): void
    {
        $building = $this->makeBuilding();
        $published = PanoramaScene::create([
            'building_id' => $building->id, 'title' => 'Cong khai', 'image_path' => 'a.jpg', 'is_published' => true,
        ]);
        PanoramaScene::create([
            'building_id' => $building->id, 'title' => 'Chua cong khai', 'image_path' => 'b.jpg', 'is_published' => false,
        ]);

        $response = $this->getJson(route('minihouse.tour.data', $building->id));

        $response->assertOk();
        $scenes = $response->json('scenes');
        $this->assertCount(1, $scenes);
        $this->assertArrayHasKey('scene_' . $published->id, $scenes);
    }

    public function test_data_endpoint_hides_hotspots_pointing_to_unpublished_scenes(): void
    {
        $building = $this->makeBuilding();
        $sceneA = PanoramaScene::create([
            'building_id' => $building->id, 'title' => 'A', 'image_path' => 'a.jpg', 'is_published' => true,
        ]);
        $sceneB = PanoramaScene::create([
            'building_id' => $building->id, 'title' => 'B (an)', 'image_path' => 'b.jpg', 'is_published' => false,
        ]);
        PanoramaHotspot::create(['scene_id' => $sceneA->id, 'target_scene_id' => $sceneB->id, 'yaw' => 0, 'pitch' => 0, 'label' => 'Sang B']);

        $response = $this->getJson(route('minihouse.tour.data', $building->id));

        $response->assertOk();
        $hotspots = $response->json('scenes.scene_' . $sceneA->id . '.hotSpots');
        $this->assertCount(0, $hotspots);
    }

    public function test_data_endpoint_includes_valid_hotspot_between_two_published_scenes(): void
    {
        $building = $this->makeBuilding();
        $sceneA = PanoramaScene::create(['building_id' => $building->id, 'title' => 'A', 'image_path' => 'a.jpg']);
        $sceneB = PanoramaScene::create(['building_id' => $building->id, 'title' => 'B', 'image_path' => 'b.jpg']);
        PanoramaHotspot::create(['scene_id' => $sceneA->id, 'target_scene_id' => $sceneB->id, 'yaw' => 12.5, 'pitch' => -3, 'label' => 'Sang B']);

        $response = $this->getJson(route('minihouse.tour.data', $building->id));

        $hotspots = $response->json('scenes.scene_' . $sceneA->id . '.hotSpots');
        $this->assertCount(1, $hotspots);
        $this->assertSame('scene_' . $sceneB->id, $hotspots[0]['sceneId']);
        $this->assertSame('Sang B', $hotspots[0]['text']);
    }

    public function test_deep_link_to_specific_scene_works(): void
    {
        $building = $this->makeBuilding();
        $scene = PanoramaScene::create(['building_id' => $building->id, 'title' => 'Phong rieng', 'image_path' => 'a.jpg']);

        $response = $this->get(route('minihouse.tour.scene', ['building' => $building->id, 'scene' => $scene->id]));

        $response->assertOk();
        $response->assertSee('scene_' . $scene->id, false);
    }

    public function test_building_with_no_scenes_returns_404(): void
    {
        $building = $this->makeBuilding();

        $response = $this->get(route('minihouse.tour.show', $building->id));

        $response->assertNotFound();
    }
}
