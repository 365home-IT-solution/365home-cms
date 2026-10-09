<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use App\Services\Camera\CameraOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Mirror App\Http\Controllers\Api\Admin\CameraOptionsController cho MiniHouse (cùng dữ liệu, khác quyền).
class CameraOptionsController extends Controller
{
    use ScopesToMinihouseBuilding;

    private const PERMISSIONS = ['view_any_cameras', 'create_cameras', 'update_cameras', 'page_manage_camera_settings'];

    public function __invoke(Request $request, CameraOptions $options): JsonResponse
    {
        foreach (self::PERMISSIONS as $permission) {
            if ($this->hasPermission($request, $permission)) {
                return response()->json(['data' => $options->all()]);
            }
        }

        return response()->json(['message' => 'Không có quyền xem camera.'], 403);
    }
}
