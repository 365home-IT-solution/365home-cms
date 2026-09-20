<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Models\User;
use App\Settings\CameraSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json([
            'go2rtc_configured' => app(CameraSettings::class)->isConfigured(),
            'data'              => $cameras->map(fn (Camera $camera) => $this->transform($camera)),
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
     * @return array<string, mixed>
     */
    private function transform(Camera $camera): array
    {
        return [
            'id'         => $camera->id,
            'name'       => $camera->name,
            'branch'     => $camera->branch ? [
                'id'   => $camera->branch->id,
                'name' => $camera->branch->name,
            ] : null,
            'status'     => $camera->status,
            'ws_url'     => $camera->wsProxyUrl(),
            'note'       => $camera->note,
        ];
    }
}
