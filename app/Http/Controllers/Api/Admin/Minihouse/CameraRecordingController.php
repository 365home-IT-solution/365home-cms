<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Api\Concerns\HandlesCameraSource;
use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Services\CameraRecordingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Mirror App\Http\Controllers\Api\Admin\CameraRecordingController (Home) — dùng chung nguyên vẹn
// App\Services\CameraRecordingService (không tự gọi Frigate trực tiếp ở đây), chỉ đổi ranh giới
// quyền sang ScopesToMinihouseBuilding (building_id) thay vì partner/branch của Home.
class CameraRecordingController extends Controller
{
    use HandlesCameraSource, ScopesToMinihouseBuilding;

    public function __construct(private readonly CameraRecordingService $recording) {}

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

        $data = $request->validate(['timezone' => 'nullable|timezone']);
        $result = $this->recording->recordingsSummary($camera, $data['timezone'] ?? 'Asia/Ho_Chi_Minh');

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

        $data = $request->validate([
            'after' => 'nullable|numeric|min:0',
            'before' => 'nullable|numeric|min:0|gt:after',
        ]);
        $result = $this->recording->recordings(
            $camera,
            isset($data['after']) ? (float) $data['after'] : null,
            isset($data['before']) ? (float) $data['before'] : null,
        );

        return $this->respond($result);
    }

    public function events(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }
        $camera = $this->findInScope($request, $id);
        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }
        $filters = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
            'after' => 'nullable|numeric|min:0',
            'before' => 'nullable|numeric|min:0',
            'label' => 'nullable|string|max:100',
            'has_clip' => 'nullable|boolean',
            'has_snapshot' => 'nullable|boolean',
            'in_progress' => 'nullable|boolean',
            'timezone' => 'nullable|timezone',
        ]);

        return $this->respond($this->recording->events($camera, $filters));
    }

    public function latestImageUrl(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }
        $camera = $this->findInScope($request, $id);
        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $camera->supportsCapability('snapshot')) {
            return $this->unsupportedResponse($camera, 'snapshot');
        }

        $url = $this->recording->latestImageUrl($camera, $error);

        if ($url === null) {
            return response()->json(['message' => $error ?? 'Không lấy được ảnh mới nhất.'], 502);
        }

        return response()->json(['data' => ['url' => $url, 'expires_in' => $camera->gatewayType() === 'cloud' ? 60 : 300]]);
    }

    public function liveOptions(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }
        $camera = $this->findInScope($request, $id);
        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return response()->json(['data' => $this->recording->liveOptions($camera)]);
    }

    public function eventMediaUrl(Request $request, int $id, string $eventId): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_cameras')) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }
        $camera = $this->findInScope($request, $id);
        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }
        $type = $request->validate(['type' => 'required|in:snapshot,clip'])['type'];
        $event = $this->recording->event($camera, $eventId);
        if (! $event['success']) {
            if (! ($event['not_found'] ?? false) && ($event['status'] ?? null) !== 404) {
                return $this->respond($event);
            }

            return response()->json(['message' => 'Không tìm thấy sự kiện của camera này.'], 404);
        }
        $availabilityField = $type === 'clip' ? 'has_clip' : 'has_snapshot';
        if (! ($event['data'][$availabilityField] ?? false)) {
            return response()->json(['message' => "Sự kiện này không có {$type}."], 404);
        }
        $url = $type === 'clip'
            ? $this->recording->eventClipUrl($camera, $eventId)
            : $this->recording->eventSnapshotUrl($camera, $eventId);

        return response()->json(['data' => ['url' => $url, 'type' => $type]]);
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

        if (! $camera->supportsCapability('playback')) {
            return $this->unsupportedResponse($camera, 'playback');
        }

        $data = $request->validate([
            'after' => 'nullable|numeric|min:0',
            'before' => 'nullable|numeric|min:0|gt:after',
        ]);
        $after = isset($data['after']) ? (float) $data['after'] : now()->subHour()->timestamp;
        $before = isset($data['before']) ? (float) $data['before'] : now()->timestamp;

        return response()->json(['data' => [
            'hls_url' => $this->recording->playbackUrl($camera, $after, $before),
            'after' => $after,
            'before' => $before,
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
            'label' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/',
            'duration' => 'nullable|integer|min:1|max:3600',
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
            ->whereIn('branch_id', $this->permittedBuildingIds($request))
            ->find($id);
    }

    private function respond(array $result, int $successStatus = 200): JsonResponse
    {
        if (! $result['success']) {
            if ($result['unsupported'] ?? false) {
                return response()->json([
                    'message' => $result['error'],
                    'code' => 'capability_not_supported',
                    'capability' => $result['capability'],
                    'gateway_type' => $result['gateway_type'],
                ], 422);
            }

            return response()->json(['message' => $result['error']], 502);
        }

        return response()->json(['data' => $result['data']], $successStatus);
    }
}
