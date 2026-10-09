<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\HandlesCameraSource;
use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Models\Partner;
use App\Models\User;
use App\Services\CameraRecordingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Xem lại lịch sử ghi hình + ghi hình thủ công (tạo/kết thúc "sự kiện" Frigate) cho APP NGOÀI —
// logic gọi Frigate thật nằm ở App\Services\CameraRecordingService, DÙNG CHUNG với
// App\Filament\Pages\CameraMonitor (giao diện CMS) — controller này chỉ lo phần HTTP (validate,
// phân quyền, format response), không tự gọi Frigate trực tiếp.
// Cùng ranh giới phân quyền partner/branch với CameraController (index/show) — 1 tài khoản chỉ xem/
// ghi được lịch sử của camera thuộc đúng đối tác/chi nhánh mình quản lý.
class CameraRecordingController extends Controller
{
    use HandlesCameraSource;

    public function __construct(private readonly CameraRecordingService $recording) {}

    // GET /api/admin/cameras/{id}/recordings/summary?timezone=
    // Tổng hợp theo GIỜ (có ghi hình/motion/object không) — dùng vẽ lịch/thanh thời gian chọn giờ.
    public function summary(Request $request, int $id): JsonResponse
    {
        if (! ($request->user()->isSuperAdmin() || $request->user()->can('page_CameraMonitor') || $request->user()->can('view_any_camera'))) {
            return response()->json(['message' => 'Bạn không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request->user(), $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate(['timezone' => 'nullable|timezone']);
        $result = $this->recording->recordingsSummary($camera, $data['timezone'] ?? 'Asia/Ho_Chi_Minh');

        return $this->respond($result);
    }

    // GET /api/admin/cameras/{id}/recordings?after=&before=
    // Danh sách đoạn ghi hình trong khoảng thời gian (unix timestamp giây) — không truyền gì thì
    // Frigate tự lấy 1 giờ gần nhất.
    public function index(Request $request, int $id): JsonResponse
    {
        if (! ($request->user()->isSuperAdmin() || $request->user()->can('page_CameraMonitor') || $request->user()->can('view_any_camera'))) {
            return response()->json(['message' => 'Bạn không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request->user(), $id);

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
        if (! ($request->user()->isSuperAdmin() || $request->user()->can('page_CameraMonitor') || $request->user()->can('view_any_camera'))) {
            return response()->json(['message' => 'Bạn không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request->user(), $id);
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
        if (! ($request->user()->isSuperAdmin() || $request->user()->can('page_CameraMonitor') || $request->user()->can('view_any_camera'))) {
            return response()->json(['message' => 'Bạn không có quyền xem camera.'], 403);
        }
        $camera = $this->findInScope($request->user(), $id);
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
        if (! ($request->user()->isSuperAdmin() || $request->user()->can('page_CameraMonitor') || $request->user()->can('view_any_camera'))) {
            return response()->json(['message' => 'Không có quyền xem camera.'], 403);
        }
        $camera = $this->findInScope($request->user(), $id);
        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return response()->json(['data' => $this->recording->liveOptions($camera)]);
    }

    public function eventMediaUrl(Request $request, int $id, string $eventId): JsonResponse
    {
        if (! ($request->user()->isSuperAdmin() || $request->user()->can('page_CameraMonitor') || $request->user()->can('view_any_camera'))) {
            return response()->json(['message' => 'Bạn không có quyền xem camera.'], 403);
        }
        $camera = $this->findInScope($request->user(), $id);
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

    // GET /api/admin/cameras/{id}/playback-url?after=&before=
    // Trả 1 URL HLS (.m3u8) DÙNG ĐƯỢC NGAY để phát lại đoạn ghi hình trong khoảng [after, before] —
    // KHÔNG trả thẳng URL Frigate thật (app không có cookie đăng nhập Frigate để tải được) mà trả
    // URL đi qua proxy media của chính server này (CameraMediaProxyController), ký kèm token ngắn
    // hạn (App\Support\CameraMediaToken) — proxy đó tự gắn cookie Frigate khi chuyển tiếp request.
    public function playbackUrl(Request $request, int $id): JsonResponse
    {
        if (! ($request->user()->isSuperAdmin() || $request->user()->can('page_CameraMonitor') || $request->user()->can('view_any_camera'))) {
            return response()->json(['message' => 'Bạn không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request->user(), $id);

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

    // POST /api/admin/cameras/{id}/recording/start
    // Body (đều tùy chọn): label (mặc định "manual"), duration (giây, mặc định 30 — null = ghi tới
    // khi gọi .../stop), pre_capture (giây lùi lại trước thời điểm gọi API, nếu Frigate có buffer đủ).
    public function start(Request $request, int $id): JsonResponse
    {
        if (! ($request->user()->isSuperAdmin() || $request->user()->can('update_camera'))) {
            return response()->json(['message' => 'Bạn không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request->user(), $id);

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

    // POST /api/admin/cameras/{id}/recording/{eventId}/stop
    // Chỉ cần gọi khi lúc /start truyền duration=null (ghi không giới hạn thời gian) — nếu
    // /start dùng duration mặc định (30s), Frigate tự kết thúc, gọi thêm .../stop không có tác dụng
    // xấu (Frigate tự bỏ qua sự kiện đã kết thúc) nhưng cũng không cần thiết.
    public function stop(Request $request, int $id, string $eventId): JsonResponse
    {
        if (! ($request->user()->isSuperAdmin() || $request->user()->can('update_camera'))) {
            return response()->json(['message' => 'Bạn không có quyền xem camera.'], 403);
        }

        $camera = $this->findInScope($request->user(), $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $result = $this->recording->stopRecording($camera, $eventId);

        return $this->respond($result);
    }

    private function findInScope(?User $user, int $id): ?Camera
    {
        if (! $user) {
            return null;
        }

        $query = Camera::query();

        if (! $user->isSuperAdmin()) {
            $query->whereDoesntHave('partner', fn ($query) => $query->where('partner_type', Partner::TYPE_MINIHOUSE))
                ->where('partner_id', $user->partner_id)
                ->whereIn('branch_id', $user->effectiveBranchIds());
        }

        return $query->find($id);
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
