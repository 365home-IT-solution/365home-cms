<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CameraSetting;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Cấu hình server go2rtc/Frigate — MỖI ĐỐI TÁC 1 bộ riêng (App\Models\CameraSetting, thay cho
// App\Settings\CameraSettings dùng chung cũ) — CHỈ super_admin hoặc tài khoản có quyền trang
// Filament "Cấu hình web > Camera" (page_ManageCamera, xem App\Filament\Pages\Setting\ManageCamera)
// mới được đọc/sửa, KHÔNG mở cho tài khoản thường: ai đọc/đổi được thông tin này có thể đọc/đổi
// được đường vào camera của đối tác đó.
//
// Tài khoản THƯỜNG (không phải super_admin) chỉ đọc/sửa được cấu hình của ĐÚNG đối tác mình
// (auth()->user()->partner_id) — super_admin quản lý nhiều đối tác nên BẮT BUỘC truyền partner_id
// (query cho GET, body cho PUT) để biết đang thao tác trên đối tác nào.
//
// GET KHÔNG BAO GIỜ trả api_key/password THẬT — chỉ trả has_api_key/has_password (đã có giá trị hay
// chưa) để FE hiển thị đúng trạng thái ("đã cấu hình"/"chưa cấu hình") mà không lộ bí mật qua log
// request/response hay DevTools.
class CameraSettingsController extends Controller
{
    // GET /api/admin/camera-settings/partners — DÀNH RIÊNG cho super_admin, mirror
    // App\Filament\Pages\Setting\ManageCamera::partnerOptions(): danh sách đối tác để dựng ô chọn
    // "Đối tác cần cấu hình" TRƯỚC khi gọi show()/update() — tài khoản thường không cần endpoint
    // này vì luôn tự động thao tác trên đúng đối tác của mình (xem resolvePartnerId()).
    public function partners(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! ($user instanceof User) || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Chỉ super_admin mới xem được danh sách đối tác để chọn cấu hình.'], 403);
        }

        $partners = Partner::query()->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $partners]);
    }

    // GET /api/admin/camera-settings?partner_id= (partner_id bắt buộc với super_admin)
    public function show(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền xem cấu hình camera.'], 403);
        }

        $partnerId = $this->resolvePartnerId($request);

        if ($partnerId === null) {
            return response()->json(['message' => 'Thiếu partner_id.'], 422);
        }

        // partner_id do super_admin tự truyền lên (tài khoản thường luôn dùng partner_id thật của
        // chính họ, không qua nhánh này nên luôn hợp lệ) — phải xác minh tồn tại thật, KHÔNG tin
        // thẳng: đọc với partner_id giả vô hại (CameraSetting::forPartner() chỉ trả instance chưa
        // lưu), nhưng update() sẽ cố INSERT với partner_id không tồn tại, vi phạm khoá ngoại
        // camera_settings.partner_id -> partners.id, ném lỗi DB thô (500) thay vì 422 rõ ràng.
        if (! Partner::whereKey($partnerId)->exists()) {
            return response()->json(['message' => 'Đối tác không tồn tại.'], 422);
        }

        $settings = CameraSetting::forPartner($partnerId);

        return response()->json(['data' => [
            'partner_id'       => $partnerId,
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

        $partnerId = $this->resolvePartnerId($request);

        if ($partnerId === null) {
            return response()->json(['message' => 'Thiếu partner_id.'], 422);
        }

        if (! Partner::whereKey($partnerId)->exists()) {
            return response()->json(['message' => 'Đối tác không tồn tại.'], 422);
        }

        $data = $request->validate([
            'base_url' => 'nullable|url|max:255',
            'api_key'  => 'nullable|string|max:255',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
        ]);

        $settings = CameraSetting::forPartner($partnerId);

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
            'partner_id'      => $partnerId,
            'base_url'        => $settings->base_url,
            'username'        => $settings->username,
            'has_api_key'     => filled($settings->api_key),
            'has_password'    => filled($settings->password),
            'has_credentials' => $settings->hasFrigateCredentials(),
            'is_configured'   => $settings->isConfigured(),
        ]]);
    }

    // Tài khoản thường CHỈ được thao tác trên ĐÚNG đối tác của mình — bỏ qua partner_id client tự
    // gửi lên nếu có (không tin), luôn dùng partner_id thật của user. super_admin PHẢI tự truyền
    // partner_id (không có "đối tác của chính họ" để mặc định) — trả null nếu thiếu, xem 422 ở trên.
    private function resolvePartnerId(Request $request): ?string
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return $user->partner_id;
        }

        $partnerId = (string) $request->input('partner_id', '');

        return $partnerId !== '' ? $partnerId : null;
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
