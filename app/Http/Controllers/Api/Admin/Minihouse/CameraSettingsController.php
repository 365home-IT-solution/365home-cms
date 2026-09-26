<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\CameraSetting;

// Mirror App\Http\Controllers\Api\Admin\CameraSettingsController (Home) — khác đúng 1 chỗ: Home khoá
// cấu hình theo "partner_id" (1 đối tác = 1 server), ở đây khoá theo "building_id" (1 Toà nhà = 1
// server) vì MiniHouse chỉ có 1 đối tác nội bộ cố định nên lọc theo đối tác vô nghĩa — xem
// Modules\Minihouse\App\Models\CameraSetting. CHỈ super_admin hoặc tài khoản có quyền
// "page_manage_camera_settings" mới đọc/sửa được — ai đọc/đổi được thông tin này có thể đọc/đổi được
// đường vào camera của Toà nhà đó.
class CameraSettingsController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/camera-settings/buildings — DÀNH RIÊNG super_admin, mirror
    // App\Filament\Pages\Setting\ManageCamera::partnerOptions() (Home) / ManageCameraSettings::
    // buildingOptions() (MiniHouse, Filament): danh sách Toà nhà để dựng ô chọn TRƯỚC khi gọi
    // show()/update() — tài khoản thường không cần vì chỉ thao tác trên Toà nhà được phân quyền
    // (xem permittedBuildingIds()).
    public function buildings(Request $request): JsonResponse
    {
        $permitted = $this->permittedBuildingIds($request);

        $buildings = Building::withoutGlobalScope('activeBuilding')
            ->whereIn('id', $permitted)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $buildings]);
    }

    // GET /api/admin/minihouse/camera-settings?building_id=
    public function show(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền xem cấu hình camera.'], 403);
        }

        $buildingId = $request->integer('building_id');

        if (! $buildingId || ! $this->isBuildingAllowed($request, $buildingId)) {
            return response()->json(['message' => 'Thiếu building_id hoặc không có quyền trên Toà nhà này.'], 422);
        }

        $settings = CameraSetting::forBuilding($buildingId);

        return response()->json(['data' => $this->transform($buildingId, $settings)]);
    }

    // PUT/PATCH /api/admin/minihouse/camera-settings
    //
    // "api_key"/"password": bỏ qua field (không gửi lên) = GIỮ NGUYÊN giá trị cũ. Gửi lên chuỗi RỖNG
    // mới thật sự xoá giá trị đang lưu — cùng quy ước với Home.
    public function update(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền sửa cấu hình camera.'], 403);
        }

        $data = $request->validate([
            'building_id' => 'required|integer',
            'base_url'    => 'nullable|url|max:255',
            'api_key'     => 'nullable|string|max:255',
            'username'    => 'nullable|string|max:255',
            'password'    => 'nullable|string|max:255',
        ]);

        $buildingId = (int) $data['building_id'];

        if (! $this->isBuildingAllowed($request, $buildingId)) {
            return response()->json(['message' => 'Không có quyền cấu hình camera cho Toà nhà này.'], 403);
        }

        $settings = CameraSetting::forBuilding($buildingId);

        if ($request->has('base_url')) {
            $settings->base_url = $data['base_url'];
        }
        if ($request->has('api_key')) {
            $settings->api_key = $data['api_key'];
        }
        if ($request->has('username')) {
            $settings->username = $data['username'];
        }
        if ($request->has('password')) {
            $settings->password = $data['password'];
        }

        $settings->building_id = $buildingId;
        $settings->save();

        return response()->json(['data' => $this->transform($buildingId, $settings)]);
    }

    private function authorized(Request $request): bool
    {
        return $this->hasPermission($request, 'page_manage_camera_settings');
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(int $buildingId, CameraSetting $settings): array
    {
        return [
            'building_id'     => $buildingId,
            'base_url'        => $settings->base_url,
            'username'        => $settings->username,
            'has_api_key'     => filled($settings->api_key),
            'has_password'    => filled($settings->password),
            'has_credentials' => $settings->hasFrigateCredentials(),
            'is_configured'   => $settings->isConfigured(),
        ];
    }
}
