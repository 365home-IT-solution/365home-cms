<?php

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\PanoramaHotspot;
use Modules\Minihouse\App\Models\PanoramaScene;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Zone;

// Dữ liệu mẫu "khách sạn 4 phòng/tầng" — SỬA 2026-09-12 (lần 6, BẢN CHUẨN CUỐI): người dùng gửi 2 ảnh
// hành lang THẬT RIÊNG BIỆT cho từng tầng, đúng chuẩn 2:1 equirectangular (không méo khi resize):
//   - hl.png  -> grand-hallway-floor1.jpg: hành lang tầng 1, biển chỉ dẫn "↑ TẦNG 2" ở cầu thang.
//   - hl2.png -> grand-hallway-floor2.jpg: hành lang tầng 2, biển chỉ dẫn "↓ TẦNG 1" ở cầu thang.
// Quy ước đánh số PHÒNG THẬT (người dùng xác nhận): SỐ ĐẦU = TẦNG (101-105 = tầng 1, 201-205 = tầng
// 2). Cặp phòng theo dãy đo được từ ảnh KHÔNG phải "101/102 cùng dãy" như đoán ban đầu, mà là:
//   Dãy trái  = cửa GẦN camera + cửa XA (sát cầu thang):   101 ↔ 105   (tầng 2: 201 ↔ 205)
//   Dãy phải  = cửa GẦN camera + cửa XA (sát cầu thang):   102 ↔ 104   (tầng 2: 202 ↔ 204)
// CHÚ Ý: phòng 103/203 KHÔNG được dựng — không thấy rõ bảng số của 2 phòng này trong cả 2 ảnh (bị che/
// quá xa để đọc), nên KHÔNG bịa toạ độ hotspot cho phòng chưa xác nhận được vị trí thật, tránh lặp lại
// lỗi "làm loạn hotspot" đã bị nhắc trước đó. Khi xác định được vị trí thật của 103/203, chỉ cần thêm
// 1 mục vào mảng $doors khi gọi addFloor().
class SeedGrandHotelDemoCommand extends Command
{
    protected $signature = 'minihouse:seed-grand-hotel-demo';

    protected $description = 'Tạo toà khách sạn 4 phòng/tầng (2 tầng, số đầu = tầng) từ 2 ảnh hành lang thật do người dùng cung cấp';

    private const HALLWAY_FLOOR1_IMAGE = 'minihouse/panoramas/grand-hallway-floor1.jpg';
    private const HALLWAY_FLOOR2_IMAGE = 'minihouse/panoramas/grand-hallway-floor2.jpg';
    private const ROOM_IMAGE           = 'minihouse/panoramas/grand-room.jpg';     // Poly Haven "Hotel Room"
    private const BATHROOM_IMAGE       = 'minihouse/panoramas/grand-bathroom.jpg'; // Poly Haven "Modern Bathroom"
    private const BALCONY_IMAGE        = 'minihouse/panoramas/grand-balcony.jpg';  // Poly Haven "Balcony"

    private const THUMBNAILS = [
        self::HALLWAY_FLOOR1_IMAGE => 'minihouse/panoramas/grand-hallway-floor1-thumb.jpg',
        self::HALLWAY_FLOOR2_IMAGE => 'minihouse/panoramas/grand-hallway-floor2-thumb.jpg',
        self::ROOM_IMAGE           => 'minihouse/panoramas/grand-room-thumb.jpg',
        self::BATHROOM_IMAGE       => 'minihouse/panoramas/grand-bathroom-thumb.jpg',
        self::BALCONY_IMAGE        => 'minihouse/panoramas/grand-balcony-thumb.jpg',
    ];

    public function handle(): int
    {
        $zone = Zone::firstOrCreate(['name' => 'Khu Quận 1']);

        $existing = Building::where('name', 'Khách sạn Grand Palace')->first();
        if ($existing) {
            PanoramaScene::where('building_id', $existing->id)->delete();
            Room::where('building_id', $existing->id)->forceDelete();
            $existing->delete();
        }

        $building = Building::create([
            'zone_id' => $zone->id,
            'name'    => 'Khách sạn Grand Palace',
            'address' => '88 Đường Đồng Khởi, Quận 1, TP.HCM',
        ]);

        // Tầng 1: ảnh MỚI "hl.png" (chuẩn 2:1 thật, không méo) — đo lại vị trí, KHÔNG đối xứng như ảnh
        // cũ: 101 (gần trái) ↔ 105 (xa trái, sát cầu thang) là 1 dãy; 102 (gần phải) ↔ 104 (xa phải,
        // sát cầu thang) là dãy còn lại — khác thứ tự "101/102 cùng dãy" của bản trước.
        $hallway1 = $this->addFloor($building, floor: 1, image: self::HALLWAY_FLOOR1_IMAGE, sortBase: 0, doors: [
            ['code' => 101, 'row' => 1, 'col' => 1, 'yaw' => -118, 'pitch' => -3],
            ['code' => 105, 'row' => 1, 'col' => 2, 'yaw' => -45, 'pitch' => 6],
            ['code' => 104, 'row' => 2, 'col' => 2, 'yaw' => 43, 'pitch' => 6],
            ['code' => 102, 'row' => 2, 'col' => 1, 'yaw' => 116, 'pitch' => -3],
        ]);

        // Tầng 2: ảnh MỚI "hl2.png" (chuẩn 2:1 thật) — CÙNG bố cục camera với ảnh tầng 1 (chỉ khác biển
        // "↓ TẦNG 1" thay vì "↑ TẦNG 2"), nên toạ độ hotspot giống hệt tầng 1: 201 (gần trái) ↔ 205 (xa
        // trái) là 1 dãy; 202 (gần phải) ↔ 204 (xa phải) là dãy còn lại.
        $hallway2 = $this->addFloor($building, floor: 2, image: self::HALLWAY_FLOOR2_IMAGE, sortBase: 100, doors: [
            ['code' => 201, 'row' => 1, 'col' => 1, 'yaw' => -118, 'pitch' => -3],
            ['code' => 205, 'row' => 1, 'col' => 2, 'yaw' => -45, 'pitch' => 6],
            ['code' => 204, 'row' => 2, 'col' => 2, 'yaw' => 43, 'pitch' => 6],
            ['code' => 202, 'row' => 2, 'col' => 1, 'yaw' => 116, 'pitch' => -3],
        ]);

        // Cầu thang THẬT nhìn thấy giữa cả 2 ảnh (yaw 0, xem biển "TẦNG 2"/"TẦNG 1" chỉ dẫn trong từng
        // ảnh) — cùng bố cục camera ở cả 2 tầng nên dùng chung 1 pitch.
        $this->link($hallway1, $hallway2, yaw: 0, pitch: 8, label: 'Lên tầng 2');
        $this->link($hallway2, $hallway1, yaw: 0, pitch: 8, label: 'Xuống tầng 1');

        $this->info("Đã tạo \"{$building->name}\": 8 phòng (101,102,104,105 tầng 1; 201,202,204,205 tầng 2), mỗi tầng 1 ảnh hành lang thật riêng, mỗi phòng có WC + ban công riêng, cầu thang thật nối 2 tầng.");
        $this->info('Xem thử tại: ' . route('minihouse.tour.show', $building->id));

        return self::SUCCESS;
    }

    /**
     * Dựng 1 tầng: hành lang (ảnh thật riêng của tầng đó) + N phòng, mỗi phòng 1 mục trong $doors:
     * {code, row (1=dãy trái, 2=dãy phải), col (1=gần camera, 2=xa/sát cầu thang), yaw, pitch} — yaw/
     * pitch đo trực tiếp trên ảnh (vị trí cửa thật), KHÔNG dùng chung công thức cố định giữa các tầng
     * vì mỗi ảnh chụp góc hơi khác nhau.
     */
    private function addFloor(Building $building, int $floor, string $image, array $doors, int $sortBase): PanoramaScene
    {
        $hallway = PanoramaScene::create([
            'building_id' => $building->id, 'title' => "Hành lang (Tầng {$floor})", 'floor' => $floor, 'sort_order' => $sortBase,
            ...$this->sceneImage($image),
        ]);

        foreach ($doors as $i => $door) {
            $room = Room::create([
                'building_id' => $building->id, 'code' => (string) $door['code'], 'floor' => $floor,
                'position_row' => $door['row'], 'position_col' => $door['col'], 'area' => 22, 'price' => 3_800_000, 'status' => Room::STATUS_EMPTY,
            ]);

            $this->addRoom($building, $hallway, $room, "Phòng {$door['code']}", $sortBase + 10 + $i * 10, yawFromCorridor: $door['yaw'], pitchFromCorridor: $door['pitch'], floor: $floor);
        }

        return $hallway;
    }

    // Tạo đủ bộ 3 cảnh của 1 phòng (phòng chính + WC + ban công) và nối đúng hotspot 2 chiều với
    // hành lang — dùng LẠI đúng 1 ảnh phòng/WC/ban công thật cho mọi phòng (nguồn ảnh 360° nội thất
    // thật, miễn phí bản quyền, rất hạn chế) nhưng VỊ TRÍ HOTSPOT trong từng ảnh luôn khớp đúng cửa
    // thật, không đổi theo phòng.
    private function addRoom(Building $building, PanoramaScene $corridor, Room $room, string $label, int $sortBase, float $yawFromCorridor, float $pitchFromCorridor, int $floor): void
    {
        $roomScene = PanoramaScene::create([
            'building_id' => $building->id, 'room_id' => $room->id, 'title' => $label, 'floor' => $floor, 'sort_order' => $sortBase,
            ...$this->sceneImage(self::ROOM_IMAGE),
        ]);
        $this->link($corridor, $roomScene, yaw: $yawFromCorridor, pitch: $pitchFromCorridor, label: "Vào {$label}");
        $this->link($roomScene, $corridor, yaw: -132, pitch: -18, label: 'Ra hành lang');

        $bathroom = PanoramaScene::create([
            'building_id' => $building->id, 'room_id' => $room->id, 'title' => "{$label} - Nhà vệ sinh", 'floor' => $floor, 'sort_order' => $sortBase + 1,
            ...$this->sceneImage(self::BATHROOM_IMAGE),
        ]);
        $this->link($roomScene, $bathroom, yaw: 170, pitch: -15, label: 'Vào nhà vệ sinh');
        $this->link($bathroom, $roomScene, yaw: 119, pitch: -14, label: 'Quay lại phòng');

        $balcony = PanoramaScene::create([
            'building_id' => $building->id, 'room_id' => $room->id, 'title' => "{$label} - Ban công", 'floor' => $floor, 'sort_order' => $sortBase + 2,
            ...$this->sceneImage(self::BALCONY_IMAGE),
        ]);
        $this->link($roomScene, $balcony, yaw: 50, pitch: 3, label: 'Ra ban công');
        $this->link($balcony, $roomScene, yaw: 24, pitch: -5, label: 'Quay lại phòng');
    }

    /** @return array{image_path: string, thumbnail_path: ?string} */
    private function sceneImage(string $path): array
    {
        return ['image_path' => $path, 'thumbnail_path' => self::THUMBNAILS[$path] ?? null];
    }

    private function link(PanoramaScene $from, PanoramaScene $to, float $yaw, float $pitch, string $label): void
    {
        PanoramaHotspot::create([
            'scene_id' => $from->id, 'target_scene_id' => $to->id,
            'yaw' => $yaw, 'pitch' => $pitch, 'label' => $label,
        ]);
    }
}
