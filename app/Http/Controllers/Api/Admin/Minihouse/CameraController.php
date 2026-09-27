<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Services\Go2RtcClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\CameraSetting;
use Modules\Minihouse\App\Support\HomestayBridge;

// Mirror App\Http\Controllers\Api\Admin\CameraController (Home) — dùng CHUNG bảng "cameras"/model
// App\Models\Camera (không có model/bảng riêng cho MiniHouse), chỉ đổi ranh giới quyền: Home lọc theo
// partner_id + effectiveBranchIds() (User), còn ở đây lọc theo partner_id CỐ ĐỊNH của MiniHouse
// (HomestayBridge::PARTNER_ID) + ScopesToMinihouseBuilding (building_id, KHÔNG dùng global scope vì
// nó chỉ bật trong đúng panel Filament minihouse-admin — xem trait).
class CameraController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/cameras?status=1&building_id=2107
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        if ($request->filled('building_id') && ! in_array((int) $request->integer('building_id'), $permitted, true)) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        $query = Camera::query()
            ->with('branch:id,name')
            ->where('partner_id', HomestayBridge::PARTNER_ID)
            ->whereIn('branch_id', $permitted);

        if ($request->query('status', '1') !== 'all') {
            $query->where('status', $request->boolean('status', true));
        }

        if ($request->filled('building_id')) {
            $query->where('branch_id', $request->integer('building_id'));
        }

        $cameras = $query->orderBy('name')->get();

        return response()->json([
            'data' => $cameras->map(fn (Camera $camera) => $this->transform($camera)),
        ]);
    }

    // GET /api/admin/minihouse/cameras/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return response()->json(['data' => $this->transform($camera)]);
    }

    // POST /api/admin/minihouse/cameras
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_cameras')) {
            return response()->json(['message' => 'Không có quyền tạo camera.'], 403);
        }

        $data = $request->validate([
            'name'                 => 'required|string|max:255',
            // Chỉ cần duy nhất TRONG CÙNG 1 building_id (= cùng 1 server go2rtc/Frigate, mỗi Toà nhà
            // MiniHouse có thể dùng server riêng — xem CameraSettingsController) — KHÔNG kiểm tra
            // trùng cả bảng "cameras" (sẽ chặn nhầm khi trùng tên với camera Home hoặc Toà nhà khác,
            // dù 2 bên chạy 2 server hoàn toàn khác nhau).
            'stream_key'           => [
                'required', 'string', 'max:255',
                Rule::unique('cameras', 'stream_key')->where(fn ($q) => $q->where('branch_id', $request->input('building_id'))),
            ],
            'frigate_camera_name'  => 'nullable|string|max:255',
            'rtsp_url'             => 'nullable|string|max:2000',
            'building_id'          => 'required|integer',
            'status'               => 'nullable|boolean',
            'note'                 => 'nullable|string',
        ]);

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo camera cho toà nhà này.'], 403);
        }

        $camera = Camera::create([
            'partner_id'           => HomestayBridge::PARTNER_ID,
            'branch_id'            => $data['building_id'],
            'name'                 => $data['name'],
            'stream_key'           => $data['stream_key'],
            'frigate_camera_name'  => $data['frigate_camera_name'] ?? null,
            'rtsp_url'             => $data['rtsp_url'] ?? null,
            'status'               => $data['status'] ?? true,
            'note'                 => $data['note'] ?? null,
        ]);

        $warning = $this->syncGo2Rtc($camera);

        return response()->json([
            'data'    => $this->transform($camera->fresh('branch:id,name')),
            'warning' => $warning,
        ], 201);
    }

    // PUT/PATCH /api/admin/minihouse/cameras/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_cameras')) {
            return response()->json(['message' => 'Không có quyền sửa camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate([
            'name'                 => 'sometimes|required|string|max:255',
            'stream_key'           => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('cameras', 'stream_key')
                    ->where(fn ($q) => $q->where('branch_id', $request->input('building_id', $camera->branch_id)))
                    ->ignore($camera->id),
            ],
            'frigate_camera_name'  => 'nullable|string|max:255',
            'rtsp_url'             => 'nullable|string|max:2000',
            'building_id'          => 'sometimes|required|integer',
            'status'               => 'nullable|boolean',
            'note'                 => 'nullable|string',
        ]);

        $originalBranchId  = $camera->branch_id;
        $originalStreamKey = $camera->stream_key;

        if (array_key_exists('building_id', $data)) {
            if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
                return response()->json(['message' => 'Không có quyền chuyển camera sang toà nhà này.'], 403);
            }

            $data['branch_id'] = $data['building_id'];
            unset($data['building_id']);
        }

        $camera->update($data);

        $warning = $this->syncGo2Rtc($camera, $originalStreamKey, $originalBranchId);

        return response()->json([
            'data'    => $this->transform($camera->fresh('branch:id,name')),
            'warning' => $warning,
        ]);
    }

    // DELETE /api/admin/minihouse/cameras/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_cameras')) {
            return response()->json(['message' => 'Không có quyền xoá camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $camera->delete();

        return response()->json(['message' => 'Đã xoá camera.']);
    }

    private function findInScope(Request $request, int $id): ?Camera
    {
        return Camera::query()
            ->where('partner_id', HomestayBridge::PARTNER_ID)
            ->whereIn('branch_id', $this->permittedBuildingIds($request))
            ->find($id);
    }

    // originalBranchId khác null + khác branch_id hiện tại nghĩa là camera vừa CHUYỂN TOÀ NHÀ — coi
    // như đổi SERVER go2rtc luôn (mỗi Toà nhà 1 server riêng), phải xoá nguồn ở server CŨ trước khi
    // khai báo lại ở server MỚI, kể cả khi "stream_key" không đổi.
    private function syncGo2Rtc(Camera $camera, ?string $originalStreamKey = null, ?int $originalBranchId = null): ?string
    {
        if (blank($camera->rtsp_url)) {
            return null;
        }

        $streamKeyChanged = $originalStreamKey !== null && $originalStreamKey !== $camera->stream_key;
        $branchChanged    = $originalBranchId !== null && $originalBranchId !== $camera->branch_id;

        if ($streamKeyChanged || $branchChanged) {
            (new Go2RtcClient(CameraSetting::forBuilding($originalBranchId ?? $camera->branch_id)))
                ->deleteStream($originalStreamKey ?? $camera->stream_key);
        }

        return (new Go2RtcClient($camera->resolveCameraSettings()))->addStream($camera->stream_key, $camera->rtsp_url);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Camera $camera): array
    {
        return [
            'id'                   => $camera->id,
            'name'                 => $camera->name,
            'stream_key'           => $camera->stream_key,
            'frigate_camera_name'  => $camera->frigateCameraName(),
            'branch'               => $camera->branch ? [
                'id'   => $camera->branch->id,
                'name' => $camera->branch->name,
            ] : null,
            'status'             => $camera->status,
            'ws_url'             => $camera->wsProxyUrl(),
            'go2rtc_configured'  => $camera->resolveCameraSettings()->isConfigured(),
            'note'               => $camera->note,
        ];
    }
}
