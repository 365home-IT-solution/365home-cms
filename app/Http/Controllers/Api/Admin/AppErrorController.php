<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AppError;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Lỗi app tự báo (bảng `app_errors`, ghi bởi App\Http\Controllers\Api\AppErrorReportController) —
 * danh sách RIÊNG cho Super Admin, tách khỏi GET /api/admin/notifications. Mỗi item là MỘT lỗi đã
 * gộp: 'count' là tổng số lần gặp trên mọi máy, các field route/url/message/reporter/app là của lần
 * báo gần nhất.
 *
 * Mỗi lỗi thuộc MỘT mục nghiệp vụ ('module': orders, payments, rooms... — App\Models\AppError::MODULES)
 * do BE tự phân lúc lỗi xuất hiện lần đầu, dùng để nhóm và lọc.
 *
 * Lỗi có 2 trạng thái: đang mở (mặc định) và đã xử lý (POST .../resolve). Lỗi đã xử lý mà app báo
 * lại thì tự mở lại và Super Admin nhận push như lỗi mới.
 *
 * Push (Expo/FCM) của loại này có data.type = 'app_error' kèm data.app_error_id để app mở thẳng
 * GET /api/admin/app-errors/{id}; KHÔNG có tín hiệu Socket.IO 'admin_notification.new'.
 */
class AppErrorController extends Controller
{
    /**
     * GET /api/admin/app-errors
     * ?status=open|resolved|all (mặc định open), ?module=orders|payments|... (xem modules()),
     * ?kind=api|crash|..., ?per_page=
     */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessSuperAdmin($request)) {
            return $denied;
        }

        $query = AppError::query()->orderByDesc('last_seen_at')->orderByDesc('id');

        match ($request->input('status', 'open')) {
            'all'      => null,
            'resolved' => $query->whereNotNull('resolved_at'),
            default    => $query->whereNull('resolved_at'),
        };

        if ($request->filled('kind')) {
            $query->where('kind', $request->input('kind'));
        }

        if ($request->filled('module')) {
            $query->where('module', $request->input('module'));
        }

        $errors = $query->paginate($request->integer('per_page', 20));
        $errors->getCollection()->transform(fn (AppError $error) => $this->toItem($error));

        return response()->json([
            'data'       => $errors->items(),
            'meta'       => [
                'current_page' => $errors->currentPage(),
                'last_page'    => $errors->lastPage(),
                'total'        => $errors->total(),
                'per_page'     => $errors->perPage(),
            ],
            'open_count' => AppError::whereNull('resolved_at')->count(),
        ]);
    }

    /**
     * GET /api/admin/app-errors/modules
     * Toàn bộ mục nghiệp vụ (cố định, kể cả mục chưa có lỗi) kèm số lỗi đang mở và tổng số lỗi của
     * từng mục — để app dựng bộ lọc / nhóm danh sách. 'key' dùng cho ?module= ở index().
     */
    public function modules(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessSuperAdmin($request)) {
            return $denied;
        }

        $counts = AppError::query()
            ->selectRaw('module, COUNT(*) as total, SUM(resolved_at IS NULL) as open_count')
            ->groupBy('module')
            ->get()
            ->keyBy('module');

        $modules = [];
        foreach (AppError::MODULES as $key => $label) {
            $modules[] = [
                'key'        => $key,
                'label'      => $label,
                'open_count' => (int) ($counts[$key]->open_count ?? 0),
                'total'      => (int) ($counts[$key]->total ?? 0),
            ];
        }

        return response()->json(['data' => $modules]);
    }

    /**
     * GET /api/admin/app-errors/{id}
     * Chi tiết — kèm 'detail' (body response lỗi hoặc stack trace của lần báo gần nhất).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessSuperAdmin($request)) {
            return $denied;
        }

        $error = AppError::find($id);

        if (! $error) {
            return response()->json(['message' => 'Không tìm thấy lỗi.'], 404);
        }

        return response()->json(['data' => $this->toItem($error) + ['detail' => $error->detail]]);
    }

    /**
     * POST /api/admin/app-errors/{id}/resolve
     * Đánh dấu đã xử lý — lỗi rời khỏi danh sách đang mở; app báo lại thì tự mở lại.
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessSuperAdmin($request)) {
            return $denied;
        }

        $error = AppError::find($id);

        if (! $error) {
            return response()->json(['message' => 'Không tìm thấy lỗi.'], 404);
        }

        if ($error->resolved_at === null) {
            $error->update(['resolved_at' => now()]);
        }

        return response()->json(['data' => $this->toItem($error)]);
    }

    /**
     * DELETE /api/admin/app-errors/{id}
     * Xóa hẳn 1 lỗi (app báo lại thì tạo dòng mới, đếm lại từ đầu).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessSuperAdmin($request)) {
            return $denied;
        }

        if (! AppError::whereKey($id)->delete()) {
            return response()->json(['message' => 'Không tìm thấy lỗi.'], 404);
        }

        return response()->json(['ok' => true]);
    }

    private function denyUnlessSuperAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            return null;
        }

        return response()->json(['message' => 'Chỉ super_admin mới xem được lỗi app.'], 403);
    }

    private function toItem(AppError $error): array
    {
        return [
            'id'            => $error->id,
            'kind'          => $error->kind,
            // Mục nghiệp vụ BE tự phân theo endpoint (lỗi API) hoặc màn hình (lỗi khác) — xem AppError::MODULES.
            'module'        => $error->module,
            'module_label'  => AppError::MODULES[$error->module] ?? $error->module,
            'area'          => $error->area,
            'title'         => $error->title(),
            'method'        => $error->method,
            'endpoint'      => $error->endpoint,
            'status'        => $error->status,
            'route'         => $error->route,
            'url'           => $error->url,
            'message'       => $error->message,
            'reporter'      => $error->reporter,
            // platform, os_version, version, runtime_version, update_id, channel — đúng như app gửi lên.
            'app'           => $error->app,
            'count'         => $error->occurrences,
            'first_seen_at' => optional($error->first_seen_at)->toIso8601String(),
            'last_seen_at'  => optional($error->last_seen_at)->toIso8601String(),
            'is_resolved'   => $error->resolved_at !== null,
            'resolved_at'   => optional($error->resolved_at)->toIso8601String(),
        ];
    }
}
