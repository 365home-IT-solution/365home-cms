<?php

namespace Modules\Minihouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\PanoramaScene;
use Modules\Minihouse\App\Services\PanoramaFloorPlanLayoutService;

// Tour ảo 360° CÔNG KHAI cho khách xem sơ đồ phòng — KHÔNG cần đăng nhập, giống hệt nguyên tắc route
// công khai của TenantFeedbackController (khách quét QR/mở link trực tiếp, không có tài khoản Portal
// nào). withoutGlobalScopes() khi tra Building/PanoramaScene — route công khai không có bộ lọc toà
// nhà đang active (ActiveBuildingScope chỉ áp trong panel Filament) nên phải tự tra không qua scope.
class PanoramaTourController extends Controller
{
    // GET /minihouse/tour/{building} — vào tour từ điểm ĐẦU TIÊN (ưu tiên điểm chung — sảnh/hành
    // lang, room_id NULL — vì đó mới là điểm khách "bước vào" hợp lý; nếu toà chỉ có ảnh phòng thì
    // lấy phòng đầu tiên theo thứ tự sort_order).
    public function show(Request $request, int $building): View
    {
        $buildingModel = Building::withoutGlobalScopes()->findOrFail($building);

        $entryScene = PanoramaScene::where('building_id', $building)
            ->where('is_published', true)
            ->orderByRaw('room_id IS NOT NULL')
            ->orderBy('sort_order')
            ->first();

        abort_if(! $entryScene, 404, 'Toà nhà này chưa có sơ đồ 360°.');

        return view('minihouse::portal.panorama-tour', [
            'building'      => $buildingModel,
            'initialSceneId' => $entryScene->id,
        ]);
    }

    // GET /minihouse/tour/{building}/{scene} — vào thẳng 1 điểm cụ thể (dùng cho nút "Xem thử" ở
    // trang quản trị, hoặc dán QR riêng trong từng phòng).
    public function scene(Request $request, int $building, int $scene): View
    {
        $buildingModel = Building::withoutGlobalScopes()->findOrFail($building);

        $sceneModel = PanoramaScene::where('building_id', $building)->findOrFail($scene);

        return view('minihouse::portal.panorama-tour', [
            'building'       => $buildingModel,
            'initialSceneId' => $sceneModel->id,
        ]);
    }

    // GET /minihouse/tour/{building}/data.json — toàn bộ scene + hotspot ĐÃ CÔNG KHAI của toà này,
    // đúng định dạng "scenes" mà Pannellum hiểu thẳng (xem panorama-tour.blade.php) — tách riêng
    // thành JSON endpoint thay vì nhúng thẳng vào Blade để trình duyệt CACHE được (khách bấm hotspot
    // đổi điểm không cần tải lại toàn bộ HTML).
    public function data(Request $request, int $building): JsonResponse
    {
        $scenes = PanoramaScene::where('building_id', $building)
            ->where('is_published', true)
            ->with(['hotspots' => fn ($q) => $q->whereHas('target', fn ($q2) => $q2->where('is_published', true))])
            ->get();

        $publishedIds = $scenes->pluck('id')->all();

        $payload = [];

        foreach ($scenes as $scene) {
            $payload['scene_' . $scene->id] = [
                'title'    => $scene->label(),
                'type'     => 'equirectangular',
                'panorama' => $this->assetUrl($scene->image_path),
                'yaw'      => $scene->initial_yaw,
                'pitch'    => $scene->initial_pitch,
                'hotSpots' => $scene->hotspots
                    // Phòng hộ 2 lớp — nullOnDelete() có thể để target_scene_id null nếu điểm đích bị
                    // xoá, và whereHas ở trên đã lọc điểm đích CHƯA công khai — bỏ luôn hotspot không
                    // còn trỏ tới đâu hợp lệ, tránh khách bấm vào mà không có chuyện gì xảy ra.
                    ->filter(fn ($h) => $h->target_scene_id && in_array($h->target_scene_id, $publishedIds, true))
                    ->map(fn ($h) => [
                        'pitch'   => $h->pitch,
                        'yaw'     => $h->yaw,
                        'type'    => 'scene',
                        'text'    => $h->label,
                        'sceneId' => 'scene_' . $h->target_scene_id,
                    ])
                    ->values(),
            ];
        }

        return response()->json(['scenes' => $payload]);
    }

    // GET /minihouse/tour/{building}/floorplan — sơ đồ tầng isometric TỰ SINH từ đúng dữ liệu 360°
    // hiện có (không cần nhập thêm toạ độ/kích thước tay) — xem PanoramaFloorPlanLayoutService để biết
    // cách suy toạ độ từ góc yaw thật của từng hotspot.
    public function floorplan(Request $request, int $building, PanoramaFloorPlanLayoutService $layoutService): View
    {
        $buildingModel = Building::withoutGlobalScopes()->findOrFail($building);

        $layout = $layoutService->build($buildingModel);

        abort_if($layout['nodes'] === [], 404, 'Toà nhà này chưa có sơ đồ 360° để dựng sơ đồ tầng.');

        return view('minihouse::portal.panorama-floorplan', [
            'building' => $buildingModel,
            'layout'   => $layout,
        ]);
    }

    // Gắn ?v=<thời điểm sửa file> vào URL ảnh — KHÔNG đổi tên file khi admin thay ảnh 360° mới (giữ
    // nguyên đường dẫn cũ), nên trình duyệt hoàn toàn có thể giữ ảnh CŨ trong cache và không tự tải
    // lại dù server đã có ảnh mới (đã xảy ra thật khi làm demo — server trả đúng ảnh mới 100% nhưng
    // trình duyệt vẫn hiện ảnh cũ). Thêm query string đổi theo mtime buộc trình duyệt coi đây là URL
    // MỚI mỗi khi file đổi, không cần đổi tên file trong DB.
    private function assetUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);
        $version = Storage::disk('public')->exists($path) ? Storage::disk('public')->lastModified($path) : null;

        return $version ? $url . '?v=' . $version : $url;
    }
}
