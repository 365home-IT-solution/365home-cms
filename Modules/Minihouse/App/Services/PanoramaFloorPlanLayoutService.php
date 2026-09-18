<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\PanoramaScene;

// Tự tính toạ độ sơ đồ tầng dạng isometric TỪ ĐÚNG DỮ LIỆU 360° đã có sẵn.
//
// SỬA 2026-09-12 sau phản hồi: bản đầu suy vị trí HOÀN TOÀN từ góc yaw của hotspot — nhưng yaw đo từ
// ảnh THẬT (chụp ở góc bất kỳ, VD -144°/127°/34°) không hề vuông góc/đều nhau, khiến sơ đồ ra lệch lạc,
// chồng chéo, không thẳng hàng như 1 bản vẽ mặt bằng thật (khách gửi ảnh tham khảo: hành lang thẳng
// giữa, phòng xếp đều 2 hàng). Sửa lại: ƯU TIÊN dùng `Room.position_row`/`position_col` — 2 cột có
// sẵn, ADMIN tự đặt CHÍNH XÁC khi tạo phòng (1 = dãy trên, 2 = dãy dưới, cột = thứ tự dọc hành lang) —
// đáng tin hơn góc ảnh vì đây là toạ độ lưới THẬT SỰ được nhập có chủ đích, không suy đoán. Chỉ khi
// phòng chưa có toạ độ này (building cũ/nhập vội) mới rơi về cách cũ (suy từ yaw) để vẫn ra được sơ đồ
// thay vì trống trơn.
class PanoramaFloorPlanLayoutService
{
    // Phản hồi trực quan: phòng nhiều buồng phụ (VD A-01 có cả WC+Bếp+Phòng ngủ+Ban công+Gác lửng) bị
    // khối ROOM_W/ROOM_D cố định (128x78, xem panorama-floorplan.blade.php) ép quá chật, các buồng phụ
    // đè lên nhau không phân biệt nổi. Tăng ROOM_W/ROOM_D bên JS lên 170x102 để có chỗ dàn buồng phụ —
    // PHẢI tăng COL_UNIT/ROW_UNIT tương ứng ở ĐÂY để giữ đúng khoảng hở giữa các phòng trên sơ đồ tổng
    // thể toà nhà (không đổi thì phòng sẽ chồng lấn nhau vì khối to hơn nhưng khoảng cách tâm không đổi).
    private const COL_UNIT = 4.2;   // khoảng cách giữa 2 CỘT phòng (dọc theo hành lang)
    private const ROW_UNIT = 3.15;  // khoảng cách từ hành lang tới hàng phòng trên/dưới
    private const UNIT = 3.6;       // (đường suy yaw dự phòng) khoảng cách giữa 2 điểm CHÍNH trên lưới
    private const SATELLITE_UNIT = 1.5; // khoảng cách từ 1 phòng tới điểm PHỤ của chính nó (VD nhà vệ sinh)

    // Nhận diện "loại không gian con" từ TIÊU ĐỀ cảnh 360° (nhân viên tự gõ, không có ô chọn riêng) —
    // MUỐN THÊM LOẠI MỚI trong tương lai: chỉ cần thêm 1 dòng vào đây (khoá => mảng từ khoá tiếng Việt
    // không dấu/có dấu đều được vì đã lowercase), rồi khai màu/kiểu vẽ tương ứng ở phía JS
    // (panorama-floorplan.blade.php, biến KIND_META) — KHÔNG cần sửa vòng lặp/logic nào khác ở cả 2
    // nơi. Duyệt THEO THỨ TỰ khai báo, khớp từ khoá đầu tiên tìm thấy — xếp từ khoá DÀI/CỤ THỂ hơn lên
    // trước để tránh khớp nhầm (VD "gác lửng" phải đứng trước "gác" chung chung).
    private const KIND_KEYWORDS = [
        'wc'          => ['vệ sinh', 'toilet', 'wc'],
        'mezzanine'   => ['gác lửng', 'gác xép', 'gác'],
        'bedroom'     => ['phòng ngủ', 'ngủ'],
        'kitchen'     => ['bếp'],
        'storage'     => ['kho chứa đồ', 'kho', 'tủ đồ'],
        'drying_yard' => ['sân phơi', 'sân thượng'],
        'balcony'     => ['ban công', 'logia', 'lô gia'],
    ];

    /**
     * @return array{nodes: array<int, array>, satellites: array<int, array>, edges: array<int, array>}
     */
    public function build(Building $building): array
    {
        $scenes = PanoramaScene::where('building_id', $building->id)
            ->where('is_published', true)
            ->with(['hotspots', 'room'])
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        if ($scenes->isEmpty()) {
            return ['nodes' => [], 'satellites' => [], 'edges' => []];
        }

        // "Điểm chính" = điểm chung (room_id null) HOẶC điểm ĐẦU TIÊN (sort_order nhỏ nhất) của mỗi
        // phòng — dựng thành khối lớn trên sơ đồ. Các điểm còn lại CÙNG room_id (VD nhà vệ sinh, ban
        // công) là "điểm phụ" — chỉ vẽ 1 chấm nhỏ gắn cạnh khối phòng chính của nó, không phải khối
        // riêng, để sơ đồ không rối khi 1 phòng có nhiều điểm.
        $primaryIds = [];
        $satellitesByPrimary = [];

        foreach ($scenes->groupBy(fn (PanoramaScene $s) => $s->room_id ?? ('scene_' . $s->id)) as $group) {
            if ($group->first()->room_id === null) {
                foreach ($group as $s) {
                    $primaryIds[$s->id] = true;
                }

                continue;
            }

            $sorted = $group->sortBy('sort_order')->values();
            $primary = $sorted->first();
            $primaryIds[$primary->id] = true;
            $satellitesByPrimary[$primary->id] = $sorted->slice(1)->all();
        }

        $roomPrimaryIds = array_values(array_filter(
            array_keys($primaryIds),
            fn ($id) => $scenes[$id]->room_id !== null
        ));

        // NHIỀU TẦNG: mỗi tầng là 1 lưới (x,y) HOÀN TOÀN RIÊNG — 1 phòng ở tầng 2 hàng 1 cột 1 không
        // hề đụng độ với phòng tầng 1 cũng hàng 1 cột 1 (thực tế 2 phòng đó thẳng cột nhau theo chiều
        // dọc mới đúng — xem cách JS phía Blade cộng thêm chiều cao theo tầng khi vẽ). "Đủ dữ liệu lưới
        // thật" cũng xét RIÊNG cho từng tầng — 1 tầng thiếu toạ độ không kéo tầng khác về cách suy yaw.
        $floorOfPrimary = [];
        foreach (array_keys($primaryIds) as $id) {
            $floorOfPrimary[$id] = $scenes[$id]->floor ?? 1;
        }

        $idsByFloor = [];
        foreach ($floorOfPrimary as $id => $floor) {
            $idsByFloor[$floor][] = $id;
        }

        $positions = [];
        foreach ($idsByFloor as $idsInFloor) {
            $primaryIdsThisFloor = array_fill_keys($idsInFloor, true);
            $roomIdsThisFloor = array_values(array_intersect($roomPrimaryIds, $idsInFloor));

            $hasFullGridThisFloor = $roomIdsThisFloor !== [] && collect($roomIdsThisFloor)->every(function ($id) use ($scenes) {
                $room = $scenes[$id]->room;

                return $room && $room->position_row !== null && $room->position_col !== null;
            });

            $positionsThisFloor = $hasFullGridThisFloor
                ? $this->gridPositions($scenes, $primaryIdsThisFloor, $roomIdsThisFloor)
                : $this->yawBasedPositions($scenes, $primaryIdsThisFloor);

            foreach ($positionsThisFloor as $id => $pos) {
                $positions[$id] = $pos;
            }
        }

        // Dùng cho satellites bên dưới: 1 phòng có nằm ở tầng đã đủ lưới thật hay không.
        $hasFullGridByPrimary = [];
        foreach ($idsByFloor as $idsInFloor) {
            $roomIdsThisFloor = array_values(array_intersect($roomPrimaryIds, $idsInFloor));
            $full = $roomIdsThisFloor !== [] && collect($roomIdsThisFloor)->every(function ($id) use ($scenes) {
                $room = $scenes[$id]->room;

                return $room && $room->position_row !== null && $room->position_col !== null;
            });
            foreach ($idsInFloor as $id) {
                $hasFullGridByPrimary[$id] = $full;
            }
        }

        $nodes = [];
        foreach (array_keys($primaryIds) as $id) {
            $scene = $scenes[$id];
            $nodes[] = [
                'id'        => $scene->id,
                'label'     => $scene->title,
                // Mã phòng thật (VD "A-01") — khách phản hồi nhãn trên sơ đồ đang hiện tên CẢNH 360°
                // ("Toàn cảnh phòng") giống hệt nhau ở NHIỀU phòng khác nhau, không phân biệt được phòng
                // nào là phòng nào; ưu tiên hiện mã phòng thật trên sơ đồ, giữ $scene->title chỉ để
                // dùng nội bộ (VD tiêu đề thanh "xem riêng 1 phòng").
                'roomCode'  => $scene->room?->code,
                'isCommon'  => $scene->room_id === null,
                'floor'     => $floorOfPrimary[$id],
                'x'         => $positions[$id]['x'],
                'y'         => $positions[$id]['y'],
                'thumbnail' => $this->thumbUrl($scene),
                'disconnected' => $positions[$id]['y'] === 999.0,
                // Diện tích THẬT đã khai báo ở Room (m²) — hiện kèm nhãn phòng trên sơ đồ thay vì tự
                // suy/bịa 1 con số kích thước không có thật (khối 3D vẽ theo tỉ lệ tượng trưng, không
                // theo đúng diện tích thật — chỉ NHÃN chữ là số liệu thật).
                'area'      => $scene->room?->area,
            ];
        }

        $satellites = [];
        foreach ($satellitesByPrimary as $primaryId => $group) {
            $primaryScene = $scenes[$primaryId];
            $primaryPos = $positions[$primaryId];
            $primaryRoom = $primaryScene->room;

            foreach ($group as $satelliteScene) {
                $kind = $this->guessKind($satelliteScene->title);

                if (($hasFullGridByPrimary[$primaryId] ?? false) && $primaryRoom && $primaryRoom->position_row !== null) {
                    // Ở CHẾ ĐỘ LƯỚI THẬT — KHÔNG dùng góc yaw ảnh nữa (yaw đo lúc chụp không còn khớp
                    // với hệ trục lưới trái/phải/trên/dưới mới) — đặt theo ĐÚNG vị trí kiến trúc thật
                    // của 1 phòng khách sạn: WC nép sát 1 cạnh phòng gần lối vào (phía hành lang), Ban
                    // công ở MÉP NGOÀI xa hành lang nhất (tường đối diện cửa ra vào).
                    $outwardSign = $primaryRoom->position_row <= 1.5 ? -1 : 1;
                    [$x, $y] = $kind === 'wc'
                        // 1.0 (thay vì 0.75 trước đây) để khối hộp WC (có bề rộng riêng, không chỉ 1
                        // chấm nữa) đứng tách bạch, không đè lên khối phòng chính.
                        ? [$primaryPos['x'] - self::SATELLITE_UNIT * 1.0, $primaryPos['y']]
                        : [$primaryPos['x'], $primaryPos['y'] + $outwardSign * self::SATELLITE_UNIT];
                } else {
                    // Dự phòng (chưa có lưới thật) — vẫn suy từ góc yaw thật như trước.
                    $link = $primaryScene->hotspots->firstWhere('target_scene_id', $satelliteScene->id);
                    $yaw = $link->yaw ?? 45.0;
                    [$dx, $dy] = $this->direction($yaw);
                    $x = $primaryPos['x'] + $dx * self::SATELLITE_UNIT;
                    $y = $primaryPos['y'] + $dy * self::SATELLITE_UNIT;
                }

                // "Bên trái/phải" THẬT khi đứng trong phòng nhìn ra cửa — lấy TRỰC TIẾP từ góc yaw
                // hotspot đã chụp (link ảnh 360° thật nối phòng chính -> buồng phụ này), KHÔNG suy
                // theo lưới/quy ước tự đặt như phần x/y ở trên (khách phản hồi: quy ước cũ "cửa luôn
                // bên trái" ra SAI so với ảnh thật — VD ảnh cho thấy WC bên trái, cửa ra hành lang bên
                // phải, sơ đồ cũ lại vẽ ngược). direction($yaw)[0] = sin(yaw) — dấu dương/âm đúng
                // nghĩa phải/trái theo cùng hệ quy chiếu build() đang dùng cho mọi hướng khác (xem
                // direction()). Không có hotspot link thật (building cũ/thiếu dữ liệu) -> để null, JS
                // tự rơi về quy ước mặc định (cửa trái, buồng phụ phải) như trước, không vỡ sơ đồ.
                // LƯU Ý: sin(yaw) dương/âm ĐÚNG QUY ƯỚC "phải/trái theo hướng camera nhìn ra" (khớp
                // direction() dùng cho các hướng khác) nhưng NGƯỢC LẠI với "trái/phải theo mắt người
                // XEM ảnh 360° trên màn hình" (ảnh 360 hiển thị soi gương theo trục ngang so với hướng
                // camera thật) — xác nhận thật bằng ảnh chụp Phòng 102 (khách gửi): WC hiện bên TRÁI
                // màn hình, "Ra hành lang" bên PHẢI, trong khi sin(yaw) của link WC lại dương (đáng lẽ
                // "phải" theo quy ước direction() gốc) — nên đảo dấu ở ĐÚNG 1 chỗ này.
                $sideLink = $primaryScene->hotspots->firstWhere('target_scene_id', $satelliteScene->id);
                $side = $sideLink ? ($this->direction($sideLink->yaw)[0] >= 0 ? 'left' : 'right') : null;

                $satellites[] = [
                    'id'       => $satelliteScene->id,
                    'parentId' => $primaryId,
                    'label'    => $satelliteScene->title,
                    'kind'     => $kind,
                    'x'        => $x,
                    'y'        => $y,
                    'side'     => $side,
                    'thumbnail' => $this->thumbUrl($satelliteScene),
                ];
            }
        }

        // Cạnh nối giữa 2 điểm CHÍNH (hành lang<->hành lang, hành lang<->phòng) — vẽ 1 chấm ở giữa,
        // bấm mở đúng cảnh đích. Chỉ lấy 1 chiều cho mỗi cặp (tránh vẽ trùng lặp 2 chấm cùng chỗ).
        $edges = [];
        $seenPairs = [];
        foreach (array_keys($primaryIds) as $id) {
            foreach ($scenes[$id]->hotspots as $hotspot) {
                $targetId = $hotspot->target_scene_id;

                if (! $targetId || ! isset($primaryIds[$targetId]) || $targetId === $id) {
                    continue;
                }

                $pairKey = min($id, $targetId) . '-' . max($id, $targetId);

                if (isset($seenPairs[$pairKey])) {
                    continue;
                }

                $seenPairs[$pairKey] = true;
                $edges[] = [
                    'fromId' => $id,
                    'toId'   => $targetId,
                    'label'  => $hotspot->label,
                    'kind'   => $scenes[$targetId]->room_id === null ? 'corridor' : 'room',
                ];
            }
        }

        return ['nodes' => $nodes, 'satellites' => $satellites, 'edges' => $edges];
    }

    /**
     * Xếp theo LƯỚI THẬT: cột = Room.position_col (thứ tự dọc hành lang), hàng = Room.position_row
     * (1 = dãy trên, 2 = dãy dưới — ≥3 dãy vẫn xếp tiếp ra xa dần theo đúng số hàng). Điểm CHUNG
     * (hành lang/sảnh, room_id null) đặt trên đúng "trục giữa" (y=0), toạ độ x = TRUNG BÌNH cột của
     * các phòng mà nó nối trực tiếp — nhờ vậy hành lang tự nằm giữa đúng cụm phòng của nó, thẳng hàng
     * liền mạch như 1 dải hành lang thật thay vì rải rác.
     *
     * @param  \Illuminate\Support\Collection<int, PanoramaScene>  $scenes
     * @param  array<int, bool>  $primaryIds
     * @param  array<int, int>  $roomPrimaryIds
     * @return array<int, array{x: float, y: float}>
     */
    private function gridPositions($scenes, array $primaryIds, array $roomPrimaryIds): array
    {
        $positions = [];

        foreach ($roomPrimaryIds as $id) {
            $room = $scenes[$id]->room;
            $positions[$id] = [
                'x' => $room->position_col * self::COL_UNIT,
                // row=1 -> phía trên trục giữa, row=2 -> phía dưới — hàng thứ 3 trở lên (hiếm) xa dần
                // tiếp theo cùng hướng để không chồng lên hàng 1/2.
                'y' => ($room->position_row - 1.5) * self::ROW_UNIT,
            ];
        }

        // Điểm CHUNG (hành lang/sảnh) — cần đồ thị liên kết giữa các điểm CHÍNH để biết nó nối tới
        // phòng nào, từ đó suy x = trung bình cột các phòng đó.
        $neighbors = [];
        foreach (array_keys($primaryIds) as $id) {
            foreach ($scenes[$id]->hotspots as $hotspot) {
                if ($hotspot->target_scene_id && isset($primaryIds[$hotspot->target_scene_id])) {
                    $neighbors[$id][] = $hotspot->target_scene_id;
                }
            }
        }

        $commonIds = array_values(array_filter(
            array_keys($primaryIds),
            fn ($id) => $scenes[$id]->room_id === null
        ));

        // Có thể cần vài lượt lặp: 1 điểm chung chỉ nối tới điểm chung KHÁC (chưa có x) ở lượt đầu thì
        // để lượt sau, khi điểm kia đã có x rồi mới tính được trung bình.
        $remaining = $commonIds;
        $passesLeft = count($commonIds) + 2;

        while ($remaining !== [] && $passesLeft-- > 0) {
            foreach ($remaining as $key => $id) {
                $knownX = [];
                foreach ($neighbors[$id] ?? [] as $neighborId) {
                    if (isset($positions[$neighborId])) {
                        $knownX[] = $positions[$neighborId]['x'];
                    }
                }

                if ($knownX !== []) {
                    $positions[$id] = ['x' => array_sum($knownX) / count($knownX), 'y' => 0.0];
                    unset($remaining[$key]);
                }
            }
        }

        // Điểm chung hoàn toàn cô lập (không nối trực tiếp tới đâu có toạ độ) — hiếm gặp, xếp tạm nối
        // tiếp nhau trên trục giữa thay vì mất tích khỏi sơ đồ.
        $i = 0;
        foreach ($remaining as $id) {
            $positions[$id] = ['x' => $i * self::COL_UNIT, 'y' => 0.0];
            $i++;
        }

        return $positions;
    }

    /**
     * Cách CŨ (dự phòng khi phòng CHƯA đặt position_row/position_col) — suy hướng trực tiếp từ góc
     * yaw thật của hotspot bằng BFS. Chấp nhận có thể lệch/không vuông góc vì ảnh thật chụp góc bất kỳ
     * — xem ghi chú đầu file — nhưng vẫn hơn là không có sơ đồ nào.
     *
     * @param  \Illuminate\Support\Collection<int, PanoramaScene>  $scenes
     * @param  array<int, bool>  $primaryIds
     * @return array<int, array{x: float, y: float}>
     */
    private function yawBasedPositions($scenes, array $primaryIds): array
    {
        $entry = $scenes->filter(fn (PanoramaScene $s) => isset($primaryIds[$s->id]))
            ->sortBy([['room_id', 'asc'], ['sort_order', 'asc']])
            ->first();

        $positions = [$entry->id => ['x' => 0.0, 'y' => 0.0]];
        $queue = [$entry->id];

        while ($queue !== []) {
            $currentId = array_shift($queue);
            $current = $scenes[$currentId];
            $pos = $positions[$currentId];

            foreach ($current->hotspots as $hotspot) {
                $targetId = $hotspot->target_scene_id;

                if (! $targetId || ! isset($primaryIds[$targetId]) || isset($positions[$targetId]) || ! $scenes->has($targetId)) {
                    continue;
                }

                [$dx, $dy] = $this->direction($hotspot->yaw);
                $positions[$targetId] = [
                    'x' => $pos['x'] + $dx * self::UNIT,
                    'y' => $pos['y'] + $dy * self::UNIT,
                ];
                $queue[] = $targetId;
            }
        }

        $fallbackIndex = 0;
        foreach (array_keys($primaryIds) as $id) {
            if (! isset($positions[$id])) {
                $positions[$id] = ['x' => $fallbackIndex * self::UNIT, 'y' => 999];
                $fallbackIndex++;
            }
        }

        return $positions;
    }

    /** @return array{0: float, 1: float} */
    private function direction(float $yawDegrees): array
    {
        $rad = deg2rad($yawDegrees);

        // yaw 0 = phía trước (lên trên sơ đồ), 90 = phải, 180 = phía sau, -90 = trái — khớp đúng cách
        // Pannellum hiểu yaw khi khách đứng quay mặt về hướng 0.
        return [sin($rad), -cos($rad)];
    }

    private function guessKind(string $title): string
    {
        $title = mb_strtolower($title);

        foreach (self::KIND_KEYWORDS as $kind => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($title, $keyword)) {
                    return $kind;
                }
            }
        }

        // Tên không khớp từ khoá nào đã biết — KHÔNG bỏ sót khỏi sơ đồ, vẫn vẽ được (chấm tròn thông
        // dụng, xem KIND_META.other ở panorama-floorplan.blade.php) trong lúc chờ thêm đúng từ khoá
        // vào KIND_KEYWORDS phía trên nếu đây là 1 loại không gian lặp lại nhiều lần.
        return 'other';
    }

    // Gắn ?v=<mtime> để trình duyệt không giữ ảnh CŨ trong cache khi admin thay ảnh mới nhưng giữ
    // nguyên tên file (xem giải thích đầy đủ ở PanoramaTourController::assetUrl() — cùng 1 lý do).
    private function thumbUrl(PanoramaScene $scene): string
    {
        $path = $scene->thumbnail_path ?? $scene->image_path;
        $url = Storage::disk('public')->url($path);
        $version = Storage::disk('public')->exists($path) ? Storage::disk('public')->lastModified($path) : null;

        return $version ? $url . '?v=' . $version : $url;
    }
}
