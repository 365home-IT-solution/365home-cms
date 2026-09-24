<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Settings\CameraSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Cấu hình server go2rtc/Frigate DÙNG CHUNG cho TOÀN HỆ THỐNG (App\Settings\CameraSettings — 1
// dòng cấu hình DUY NHẤT, không phân theo đối tác/chi nhánh như Camera) — CHỈ super_admin hoặc tài
// khoản có quyền trang Filament "Cấu hình web > Camera" (page_ManageCamera, xem
// App\Filament\Pages\Setting\ManageCamera) mới được đọc/sửa, KHÔNG mở cho tài khoản thường: ai đọc/
// đổi được thông tin này có thể đọc/đổi được đường vào TOÀN BỘ camera của TOÀN BỘ đối tác, không
// riêng gì đối tác của chính họ.
//
// GET KHÔNG BAO GIỜ trả api_key/password THẬT — chỉ trả has_api_key/has_password (đã có giá trị hay
// chưa) để FE hiển thị đúng trạng thái ("đã cấu hình"/"chưa cấu hình") mà không lộ bí mật qua log
// request/response hay DevTools.
class CameraSettingsController extends Controller
{
    // GET /api/admin/camera-settings
    public function show(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền xem cấu hình camera.'], 403);
        }

        $settings = app(CameraSettings::class);

        return response()->json(['data' => [
            'base_url'         => $settings->base_url,
            'username'         => $settings->username,
            'has_api_key'      => filled($settings->api_key),
            'has_password'     => filled($settings->password),
            'has_credentials'  => $settings->hasFrigateCredentials(),
            'is_configured'    => $settings->isConfigured(),
        ]]);
    }

    // PUT /api/admin/camera-settings
    //
    // "api_key"/"password": bỏ qua field (không gửi lên) = GIỮ NGUYÊN giá trị cũ — tránh bắt buộc
    // FE phải gửi lại mật khẩu mỗi lần chỉ muốn đổi địa chỉ server. Gửi lên chuỗi RỖNG mới thật sự
    // xoá giá trị đang lưu.
    public function update(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền sửa cấu hình camera.'], 403);
        }

        $data = $request->validate([
            'base_url' => 'nullable|url|max:255',
            'api_key'  => 'nullable|string|max:255',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
        ]);

        $settings = app(CameraSettings::class);

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

        $settings->save();

        return response()->json(['data' => [
            'base_url'        => $settings->base_url,
            'username'        => $settings->username,
            'has_api_key'     => filled($settings->api_key),
            'has_password'    => filled($settings->password),
            'has_credentials' => $settings->hasFrigateCredentials(),
            'is_configured'   => $settings->isConfigured(),
        ]]);
    }

    // super_admin bypass qua Gate::before; tài khoản thường phải có đúng quyền trang Filament
    // tương ứng (page_ManageCamera) — dùng $user->can() (không dùng hasPermissionTo() thuần Spatie)
    // để super_admin không bị chặn nhầm, cùng quy ước ScopesToMinihouseBuilding::hasPermission().
    private function authorized(Request $request): bool
    {
        $user = $request->user();

        return ($user instanceof User) && ($user->isSuperAdmin() || $user->can('page_ManageCamera'));
    }
}
