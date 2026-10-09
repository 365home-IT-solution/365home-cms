<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Camera\CameraOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /api/admin/camera-options — danh mục gateway, loại kết nối, hãng (kèm các ô tài khoản developer cho
// hãng cloud) và tính năng. FE dựng form cấu hình/tạo camera động từ đây, không cần cứng danh sách.
class CameraOptionsController extends Controller
{
    private const PERMISSIONS = ['view_any_camera', 'view_camera', 'create_camera', 'update_camera', 'page_CameraMonitor', 'page_ManageCamera'];

    public function __invoke(Request $request, CameraOptions $options): JsonResponse
    {
        $user = $request->user();

        $allowed = $user instanceof User
            && ($user->isSuperAdmin() || collect(self::PERMISSIONS)->contains(fn (string $permission) => $user->can($permission)));

        if (! $allowed) {
            return response()->json(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
        }

        return response()->json(['data' => $options->all()]);
    }
}
