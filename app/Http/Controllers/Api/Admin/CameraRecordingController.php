<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Camera;
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
    public function __construct(private readonly CameraRecordingService $recording)
    {
    }

    // GET /api/admin/cameras/{id}/recordings/summary?timezone=
    // Tổng hợp theo GIỜ (có ghi hình/motion/object không) — dùng vẽ lịch/thanh thời gian chọn giờ.
    public function summary(Request $request, int $id): JsonResponse
    {
        $camera = $this->findInScope($request->user(), $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $result = $this->recording->recordingsSummary(
            $camera,
            (string) $request->string('timezone', 'Asia/Ho_Chi_Minh'),
        );

        return $this->respond($result);
    }

    // GET /api/admin/cameras/{id}/recordings?after=&before=
    // Danh sách đoạn ghi hình trong khoảng thời gian (unix timestamp giây) — không truyền gì thì
    // Frigate tự lấy 1 giờ gần nhất.
    public function index(Request $request, int $id): JsonResponse
    {
        $camera = $this->findInScope($request->user(), $id);

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

    // GET /api/admin/cameras/{id}/playback-url?after=&before=
    // Trả 1 URL HLS (.m3u8) DÙNG ĐƯỢC NGAY để phát lại đoạn ghi hình trong khoảng [after, before] —
    // KHÔNG trả thẳng URL Frigate thật (app không có cookie đăng nhập Frigate để tải được) mà trả
    // URL đi qua proxy media của chính server này (CameraMediaProxyController), ký kèm token ngắn
    // hạn (App\Support\CameraMediaToken) — proxy đó tự gắn cookie Frigate khi chuyển tiếp request.
    public function playbackUrl(Request $request, int $id): JsonResponse
    {
        $camera = $this->findInScope($request->user(), $id);

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

    // POST /api/admin/cameras/{id}/recording/start
    // Body (đều tùy chọn): label (mặc định "manual"), duration (giây, mặc định 30 — null = ghi tới
    // khi gọi .../stop), pre_capture (giây lùi lại trước thời điểm gọi API, nếu Frigate có buffer đủ).
    public function start(Request $request, int $id): JsonResponse
    {
        $camera = $this->findInScope($request->user(), $id);

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

    // POST /api/admin/cameras/{id}/recording/{eventId}/stop
    // Chỉ cần gọi khi lúc /start truyền duration=null (ghi không giới hạn thời gian) — nếu
    // /start dùng duration mặc định (30s), Frigate tự kết thúc, gọi thêm .../stop không có tác dụng
    // xấu (Frigate tự bỏ qua sự kiện đã kết thúc) nhưng cũng không cần thiết.
    public function stop(Request $request, int $id, string $eventId): JsonResponse
    {
        $camera = $this->findInScope($request->user(), $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $result = $this->recording->stopRecording($eventId);

        return $this->respond($result);
    }

    private function findInScope(?User $user, int $id): ?Camera
    {
        if (! $user) {
            return null;
        }

        $query = Camera::query();

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id)
                ->whereIn('branch_id', $user->effectiveBranchIds());
        }

        return $query->find($id);
    }

    private function respond(array $result, int $successStatus = 200): JsonResponse
    {
        if (! $result['success']) {
            return response()->json(['message' => $result['error']], 502);
        }

        return response()->json(['data' => $result['data']], $successStatus);
    }
}
