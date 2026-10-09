<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Api\Concerns\HandlesCameraSource;
use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Services\CameraSourceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Building;

// Mirror App\Http\Controllers\Api\Admin\CameraController (Home) — dùng CHUNG bảng "cameras"/model
// App\Models\Camera (không có model/bảng riêng cho MiniHouse), chỉ đổi ranh giới quyền: Home lọc theo
// partner_id + effectiveBranchIds() (User), còn ở đây lọc theo partner_id CỐ ĐỊNH của MiniHouse
// (HomestayBridge::PARTNER_ID) + ScopesToMinihouseBuilding (building_id, KHÔNG dùng global scope vì
// nó chỉ bật trong đúng panel Filament minihouse-admin — xem trait).
class CameraController extends Controller
{
    use HandlesCameraSource, ScopesToMinihouseBuilding;

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
            'name' => 'required|string|max:255',
            // Chỉ cần duy nhất TRONG CÙNG 1 building_id (= cùng 1 server go2rtc/Frigate, mỗi Toà nhà
            // MiniHouse có thể dùng server riêng — xem CameraSettingsController) — KHÔNG kiểm tra
            // trùng cả bảng "cameras" (sẽ chặn nhầm khi trùng tên với camera Home hoặc Toà nhà khác,
            // dù 2 bên chạy 2 server hoàn toàn khác nhau).
            'stream_key' => [
                'required', 'string', 'max:255',
                Rule::unique('cameras', 'stream_key')->where(fn ($q) => $q->where('branch_id', $request->input('building_id'))),
            ],
            'frigate_camera_name' => 'nullable|string|max:255',
            'rtsp_url' => 'nullable|string|max:2000',
            'provider' => ['nullable', 'string', 'max:50'],
            'source_type' => ['nullable', Rule::in(Camera::SOURCE_TYPES)],
            'source_url' => 'nullable|string|max:4000',
            'external_device_id' => 'nullable|string|max:255',
            'external_channel' => 'nullable|string|max:20',
            'building_id' => 'required|integer',
            'status' => 'nullable|boolean',
            'note' => 'nullable|string',
        ]);

        $sourceType = $data['source_type'] ?? (filled($data['rtsp_url'] ?? null) ? 'rtsp' : 'existing');
        if ($error = $this->sourceError($sourceType, $data['provider'] ?? null, $data['external_device_id'] ?? null, $data['source_url'] ?? $data['rtsp_url'] ?? null)) {
            return $error;
        }

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo camera cho toà nhà này.'], 403);
        }

        $building = Building::withoutGlobalScopes()->findOrFail((int) $data['building_id']);
        $camera = Camera::create([
            'partner_id' => $building->partner_id,
            'branch_id' => $data['building_id'],
            'name' => $data['name'],
            'stream_key' => $data['stream_key'],
            'frigate_camera_name' => $data['frigate_camera_name'] ?? null,
            'rtsp_url' => $data['rtsp_url'] ?? null,
            'provider' => $data['provider'] ?? 'generic',
            'source_type' => $sourceType,
            'source_url' => $data['source_url'] ?? null,
            'managed_source' => filled($data['source_url'] ?? null) || filled($data['rtsp_url'] ?? null),
            'external_device_id' => $data['external_device_id'] ?? null,
            'external_channel' => $data['external_channel'] ?? null,
            'status' => $data['status'] ?? true,
            'note' => $data['note'] ?? null,
        ]);

        $warning = $this->syncGo2Rtc($camera);

        return response()->json([
            'data' => $this->transform($camera->fresh('branch:id,name')),
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
            'name' => 'sometimes|required|string|max:255',
            'stream_key' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('cameras', 'stream_key')
                    ->where(fn ($q) => $q->where('branch_id', $request->input('building_id', $camera->branch_id)))
                    ->ignore($camera->id),
            ],
            'frigate_camera_name' => 'nullable|string|max:255',
            'rtsp_url' => 'nullable|string|max:2000',
            'provider' => ['sometimes', 'nullable', 'string', 'max:50'],
            'source_type' => ['sometimes', Rule::in(Camera::SOURCE_TYPES)],
            'source_url' => 'nullable|string|max:4000',
            'external_device_id' => 'nullable|string|max:255',
            'external_channel' => 'nullable|string|max:20',
            'building_id' => 'sometimes|required|integer',
            'status' => 'nullable|boolean',
            'note' => 'nullable|string',
        ]);

        $resultingType = $data['source_type'] ?? $camera->source_type;
        $resultingSource = array_key_exists('source_url', $data) ? $data['source_url'] : $camera->sourceUrl();
        $resultingProvider = array_key_exists('provider', $data) ? $data['provider'] : $camera->provider;
        $resultingDevice = array_key_exists('external_device_id', $data) ? $data['external_device_id'] : $camera->external_device_id;
        if ($error = $this->sourceError($resultingType, $resultingProvider, $resultingDevice, $resultingSource)) {
            return $error;
        }

        $originalBranchId = $camera->branch_id;
        $originalStreamKey = $camera->stream_key;
        $originalCamera = $camera->replicate();

        if (array_key_exists('building_id', $data)) {
            if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
                return response()->json(['message' => 'Không có quyền chuyển camera sang toà nhà này.'], 403);
            }

            $building = Building::withoutGlobalScopes()->findOrFail((int) $data['building_id']);
            $data['branch_id'] = $building->id;
            $data['partner_id'] = $building->partner_id;
            unset($data['building_id']);
        }

        if (array_key_exists('source_url', $data) || array_key_exists('rtsp_url', $data)) {
            $data['managed_source'] = filled($data['source_url'] ?? $data['rtsp_url'] ?? null);
        }

        $camera->update($data);

        $warning = app(CameraSourceManager::class)->sync($camera, $originalStreamKey, $originalCamera);

        return response()->json([
            'data' => $this->transform($camera->fresh('branch:id,name')),
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

    public function health(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return response()->json(['data' => app(CameraSourceManager::class)->health($camera)]);
    }

    public function syncSource(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_cameras')) {
            return response()->json(['message' => 'Không có quyền sửa camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $camera->supportsCapability('source_sync')) {
            return $this->unsupportedResponse($camera, 'source_sync');
        }

        $error = app(CameraSourceManager::class)->sync($camera);

        return response()->json([
            'success' => $error === null,
            'message' => $error ?? ($camera->isManagedSource()
                ? 'Đã đồng bộ nguồn với go2rtc.'
                : 'Camera dùng nguồn có sẵn trong Frigate/go2rtc, không có gì để đồng bộ.'),
        ], $error === null ? 200 : 502);
    }

    private function findInScope(Request $request, int $id): ?Camera
    {
        return Camera::query()
            ->whereIn('branch_id', $this->permittedBuildingIds($request))
            ->find($id);
    }

    // originalBranchId khác null + khác branch_id hiện tại nghĩa là camera vừa CHUYỂN TOÀ NHÀ — coi
    // như đổi SERVER go2rtc luôn (mỗi Toà nhà 1 server riêng), phải xoá nguồn ở server CŨ trước khi
    // khai báo lại ở server MỚI, kể cả khi "stream_key" không đổi.
    private function syncGo2Rtc(Camera $camera, ?string $originalStreamKey = null, ?int $originalBranchId = null): ?string
    {
        return app(CameraSourceManager::class)->sync($camera, $originalStreamKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Camera $camera): array
    {
        return [
            'id' => $camera->id,
            'name' => $camera->name,
            'stream_key' => $camera->stream_key,
            'frigate_camera_name' => $camera->frigateCameraName(),
            'provider' => $camera->provider,
            'source_type' => $camera->source_type,
            'external_device_id' => $camera->external_device_id,
            'managed_source' => $camera->isManagedSource(),
            'connection_status' => $camera->connection_status,
            'connection_message' => $camera->connection_message,
            'last_checked_at' => $camera->last_checked_at?->toIso8601String(),
            'last_online_at' => $camera->last_online_at?->toIso8601String(),
            'branch' => $camera->branch ? [
                'id' => $camera->branch->id,
                'name' => $camera->branch->name,
            ] : null,
            'status' => $camera->status,
            'ws_url' => $camera->wsProxyUrl(),
            'go2rtc_configured' => $camera->resolveCameraSettings()->isGo2RtcConfigured(),
            'note' => $camera->note,
            ...$this->gatewayFields($camera),
        ];
    }
}
