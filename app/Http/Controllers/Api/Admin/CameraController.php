<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Models\User;
use App\Services\Go2RtcClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Category\Entities\Category;

// Danh sách camera cho app riêng (Bearer token, cùng tài khoản đăng nhập với các API admin.api
// khác) — trả kèm SẴN URL WebSocket đã ký token, app chỉ cần mở kết nối vào đúng URL đó là xem
// được, không cần tự đăng nhập Frigate hay biết tài khoản/mật khẩu Frigate ở đâu cả. Xem
// App\Models\Camera::wsProxyUrl() (cùng 1 nguồn dùng chung với trang "Xem camera" trên CMS,
// App\Filament\Pages\CameraMonitor) và websocket/server.js (nơi thật sự nhận kết nối này).
//
// Global scope BelongsToBranch/BelongsToPartner của Camera CHỈ áp dụng trong Filament panel (xem
// App\Support\AdminPanelContext) — route API này KHÔNG đi qua panel nên phải tự lọc thủ công, cùng
// nguyên tắc mọi controller Api\Admin khác (WarehouseCategoryController, BranchController...).
class CameraController extends Controller
{
    /**
     * GET /api/admin/cameras
     *
     * ?status=1|0 — lọc theo đang bật/tắt (mặc định: chỉ trả camera đang bật, giống trang "Xem
     * camera" trên CMS). Truyền status=all để lấy cả camera đang tắt.
     * ?branch_id= — lọc thêm đúng 1 chi nhánh cụ thể (phải nằm trong phạm vi tài khoản).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Camera::query()->with('branch:id,name');

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id)
                ->whereIn('branch_id', $user->effectiveBranchIds());
        }

        if ($request->query('status', '1') !== 'all') {
            $query->where('status', $request->boolean('status', true));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        $cameras = $query->orderBy('name')->get();

        // KHÔNG còn 1 cờ "go2rtc_configured" chung cho cả danh sách — mỗi đối tác có thể dùng
        // server Frigate RIÊNG (App\Models\CameraSetting), super_admin xem camera của NHIỀU đối tác
        // cùng lúc nên tình trạng cấu hình phải tính THEO TỪNG camera (xem transform()).
        return response()->json([
            'data' => $cameras->map(fn (Camera $camera) => $this->transform($camera)),
        ]);
    }

    /**
     * GET /api/admin/cameras/{id}
     *
     * Lấy lại ĐÚNG 1 camera kèm ws_url MỚI (token cũ hết hạn sau 12 tiếng — app gọi lại API này
     * để làm mới token thay vì phải tải lại toàn bộ danh sách).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Camera::query()->with('branch:id,name');

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id)
                ->whereIn('branch_id', $user->effectiveBranchIds());
        }

        $camera = $query->find($id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return response()->json(['data' => $this->transform($camera)]);
    }

    /**
     * POST /api/admin/cameras
     *
     * Y HỆT hành vi CreateCamera (Filament) — "rtsp_url" chỉ điền khi camera CHƯA có sẵn trong
     * Frigate; nếu có điền, tự động khai báo nguồn với server go2rtc (App\Services\Go2RtcClient).
     * Lỗi kết nối go2rtc KHÔNG chặn việc tạo — camera vẫn được lưu, trả kèm cảnh báo để app tự báo
     * người dùng thử lại "Khai báo với go2rtc" sau (chưa có API riêng cho việc thử lại — xem ghi
     * chú ở cuối file).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name'                 => 'required|string|max:255',
            // Chỉ cần duy nhất TRONG CÙNG 1 branch_id (= cùng 1 server go2rtc/Frigate, xem
            // App\Models\Camera::resolveCameraSettings()) — KHÔNG còn kiểm tra trùng CẢ bảng
            // "cameras" như trước (2 chi nhánh/Toà nhà khác nhau chạy 2 server khác nhau, tên nguồn
            // trùng nhau không xung đột gì thật sự). Mirror ĐÚNG rule đã sửa ở CameraForm (Filament,
            // Modules\Minihouse) — tránh 1 bên (API) và 1 bên (Filament) chặn khác nhau.
            'stream_key'           => [
                'required', 'string', 'max:255',
                Rule::unique('cameras', 'stream_key')->where(fn ($q) => $q->where('branch_id', $request->input('branch_id'))),
            ],
            'frigate_camera_name'  => 'nullable|string|max:255',
            'rtsp_url'             => 'nullable|string|max:2000',
            'branch_id'            => 'required|integer',
            'status'               => 'nullable|boolean',
            'note'                 => 'nullable|string',
        ]);

        $branch = $this->resolveAllowedBranch($user, (int) $data['branch_id']);

        if (! $branch) {
            return response()->json(['message' => 'Không có quyền tạo camera cho chi nhánh này.'], 403);
        }

        $camera = Camera::create([
            'partner_id'           => $branch->partner_id,
            'branch_id'            => $branch->id,
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

    /**
     * PUT/PATCH /api/admin/cameras/{id}
     *
     * Y HỆT hành vi EditCamera (Filament): đổi "stream_key" thì tự xoá nguồn CŨ khỏi go2rtc trước
     * khi khai báo nguồn MỚI (tránh tồn đọng 1 nguồn "mồ côi" không ai dùng trên server go2rtc).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $camera = $this->findInScope($user, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate([
            'name'                 => 'sometimes|required|string|max:255',
            // Cùng phạm vi kiểm tra trùng như store() — duy nhất trong đúng branch_id SẼ LƯU (branch
            // mới nếu request đổi chi nhánh, branch hiện tại nếu không đổi), không phải cả bảng.
            'stream_key'           => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('cameras', 'stream_key')
                    ->where(fn ($q) => $q->where('branch_id', $request->input('branch_id', $camera->branch_id)))
                    ->ignore($camera->id),
            ],
            'frigate_camera_name'  => 'nullable|string|max:255',
            'rtsp_url'             => 'nullable|string|max:2000',
            'branch_id'            => 'sometimes|required|integer',
            'status'               => 'nullable|boolean',
            'note'                 => 'nullable|string',
        ]);

        if (array_key_exists('branch_id', $data)) {
            $branch = $this->resolveAllowedBranch($user, (int) $data['branch_id']);

            if (! $branch) {
                return response()->json(['message' => 'Không có quyền chuyển camera sang chi nhánh này.'], 403);
            }

            $data['branch_id']  = $branch->id;
            $data['partner_id'] = $branch->partner_id;
        }

        $originalStreamKey = $camera->stream_key;

        $camera->update($data);

        $warning = $this->syncGo2Rtc($camera, $originalStreamKey);

        return response()->json([
            'data'    => $this->transform($camera->fresh('branch:id,name')),
            'warning' => $warning,
        ]);
    }

    /**
     * DELETE /api/admin/cameras/{id}
     *
     * Dọn go2rtc tự động qua Camera::booted() (static::deleted) — CHỈ khi camera có rtsp_url (do
     * chính web này khai báo nguồn); camera tham chiếu nguồn có sẵn trong Frigate (rtsp_url trống)
     * xoá bản ghi ở đây KHÔNG được phép động tới go2rtc, xem chú thích trong App\Models\Camera.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $camera = $this->findInScope($user, $id);

        if (! $camera) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $camera->delete();

        return response()->json(['message' => 'Đã xoá camera.']);
    }

    private function findInScope(User $user, int $id): ?Camera
    {
        $query = Camera::query();

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id)
                ->whereIn('branch_id', $user->effectiveBranchIds());
        }

        return $query->find($id);
    }

    // super_admin chọn được BẤT KỲ chi nhánh gốc nào trong hệ thống; tài khoản thường chỉ chọn được
    // trong đúng effectiveBranchIds() của mình — cùng ranh giới đang áp dụng ở index()/show(). Trả
    // về chính Category (branch) tìm được để lấy luôn partner_id thật của chi nhánh đó (KHÔNG tin
    // partner_id do client tự gửi lên — luôn suy ra từ branch, giống hệt CameraResource::branchInput()
    // ở Filament).
    private function resolveAllowedBranch(User $user, int $branchId): ?Category
    {
        $branch = Category::query()
            ->where('category_type', 'product')
            ->whereNull('parent_id')
            ->find($branchId);

        if (! $branch) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return $branch;
        }

        return in_array($branchId, $user->effectiveBranchIds(), true) ? $branch : null;
    }

    // Trả về chuỗi cảnh báo (hoặc null nếu không có gì bất thường) — KHÔNG throw, để lỗi kết nối
    // go2rtc không bao giờ làm hỏng việc tạo/sửa bản ghi camera, cùng nguyên tắc CreateCamera/
    // EditCamera (Filament) đang áp dụng.
    private function syncGo2Rtc(Camera $camera, ?string $originalStreamKey = null): ?string
    {
        if (blank($camera->rtsp_url)) {
            return null;
        }

        $client = new Go2RtcClient($camera->resolveCameraSettings());

        if ($originalStreamKey !== null && $originalStreamKey !== $camera->stream_key) {
            $client->deleteStream($originalStreamKey);
        }

        return $client->addStream($camera->stream_key, $camera->rtsp_url);
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
