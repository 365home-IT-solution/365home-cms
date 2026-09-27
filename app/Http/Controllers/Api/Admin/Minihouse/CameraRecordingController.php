<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Services\CameraRecordingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Support\HomestayBridge;

// Mirror App\Http\Controllers\Api\Admin\CameraRecordingController (Home) — dùng chung nguyên vẹn
// App\Services\CameraRecordingService (không tự gọi Frigate trực tiếp ở đây), chỉ đổi ranh giới
// quyền sang ScopesToMinihouseBuilding (building_id) thay vì partner/branch của Home.
class CameraRecordingController extends Controller
{
    use ScopesToMinihouseBuilding;

    public function __construct(private readonly CameraRecordingService $recording)
    {
    }

    // GET /api/admin/minihouse/cameras/{id}/recordings/summary?timezone=
    public function summary(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $result = $this->recording->recordingsSummary(
            $camera,
            (string) $request->string('timezone', 'Asia/Ho_Chi_Minh'),
        );

        return $this->respond($result);
    }

    // GET /api/admin/minihouse/cameras/{id}/recordings?after=&before=
    public function index(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $result = $this->recording->recordings(
            $camera,
            $request->filled('after') ? (float) $request->input('after') : null,
            $request->filled('before') ? (float) $request->input('before') : null,
        );

        return $this->respond($result);
    }

    // GET /api/admin/minihouse/cameras/{id}/playback-url?after=&before=
    public function playbackUrl(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $after  = $request->filled('after') ? (float) $request->input('after') : now()->subHour()->timestamp;
        $before = $request->filled('before') ? (float) $request->input('before') : now()->timestamp;

        if ($before <= $after) {
            return response()->json(['message' => 'Tham số "before" phải lớn hơn "after".'], 422);
        }

        return response()->json(['data' => [
            'hls_url' => $this->recording->playbackUrl($camera, $after, $before),
            'after'   => $after,
            'before'  => $before,
        ]]);
    }

    // POST /api/admin/minihouse/cameras/{id}/recording/start
    public function start(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_cameras')) {
            return response()->json(['message' => 'Không có quyền ghi hình camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate([
            'label'       => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/',
            'duration'    => 'nullable|integer|min:1|max:3600',
            'pre_capture' => 'nullable|integer|min:0|max:60',
        ]);

        $result = $this->recording->startRecording(
            $camera,
            $data['label'] ?? 'manual',
            array_key_exists('duration', $data) ? $data['duration'] : 30,
            true,
            $data['pre_capture'] ?? null,
        );

        return $this->respond($result, 201);
    }

    // POST /api/admin/minihouse/cameras/{id}/recording/{eventId}/stop
    public function stop(Request $request, int $id, string $eventId): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_cameras')) {
            return response()->json(['message' => 'Không có quyền ghi hình camera.'], 403);
        }

        $camera = $this->findInScope($request, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $result = $this->recording->stopRecording($camera, $eventId);

        return $this->respond($result);
    }

    private function findInScope(Request $request, int $id): ?Camera
    {
        return Camera::query()
            ->where('partner_id', HomestayBridge::PARTNER_ID)
            ->whereIn('branch_id', $this->permittedBuildingIds($request))
            ->find($id);
    }

    private function respond(array $result, int $successStatus = 200): JsonResponse
    {
        if (! $result['success']) {
            return response()->json(['message' => $result['error']], 502);
        }

        return response()->json(['data' => $result['data']], $successStatus);
    }
}
