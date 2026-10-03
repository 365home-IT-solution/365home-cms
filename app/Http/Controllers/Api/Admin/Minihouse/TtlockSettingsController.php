<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Filament\Pages\Setting\ManageTtlockSettings;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\TtlockSetting;
use Modules\Minihouse\App\Services\ContractTtlockService;
use Modules\TTLock\App\Services\TTLockService;

// Cấu hình tài khoản TTLock THEO TỪNG TOÀ NHÀ — bản API của ManageTtlockSettings (Filament). Mirror
// tinh thần Api\Admin\Minihouse\CameraSettingsController: chỉ tài khoản có quyền
// "page_manage_ttlock_settings" (hoặc super_admin) và chỉ trên Toà nhà được phân quyền. client_secret/
// password_md5 là bí mật — KHÔNG BAO GIỜ trả về trong response (chỉ has_client_secret/has_password).
class TtlockSettingsController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/ttlock-settings/buildings
    public function buildings(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền xem cấu hình TTLock.'], 403);
        }

        $buildings = Building::withoutGlobalScope('activeBuilding')
            ->whereIn('id', $this->permittedBuildingIds($request))
            ->orderBy('name')
            ->get(['id', 'name']);

        $configured = TtlockSetting::query()->whereIn('building_id', $buildings->pluck('id'))->get()->keyBy('building_id');

        return response()->json(['data' => $buildings->map(fn ($b) => [
            'id'            => $b->id,
            'name'          => $b->name,
            'is_configured' => (bool) $configured->get($b->id)?->isConfigured(),
            'is_active'     => (bool) $configured->get($b->id)?->is_active,
        ])->values()]);
    }

    // GET /api/admin/minihouse/ttlock-settings?building_id=
    public function show(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền xem cấu hình TTLock.'], 403);
        }

        $buildingId = $request->integer('building_id');

        if (! $buildingId || ! $this->isBuildingAllowed($request, $buildingId)) {
            return response()->json(['message' => 'Thiếu building_id hoặc không có quyền trên Toà nhà này.'], 422);
        }

        return response()->json(['data' => $this->transform($buildingId, TtlockSetting::forBuilding($buildingId))]);
    }

    // PUT/PATCH /api/admin/minihouse/ttlock-settings
    //
    // "client_secret"/"password": bỏ qua field (không gửi lên) = GIỮ NGUYÊN giá trị cũ. "password" nhận
    // mật khẩu thường hoặc MD5 32 ký tự (tự chuyển sang MD5). Lần đầu cấu hình phải đủ 4 field bắt buộc.
    public function update(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền sửa cấu hình TTLock.'], 403);
        }

        $data = $request->validate([
            'building_id'   => 'required|integer',
            'client_id'     => 'nullable|string|max:100',
            'client_secret' => 'nullable|string|max:100',
            'username'      => 'nullable|string|max:100',
            'password'      => 'nullable|string|max:64',
            'api_base'      => 'nullable|url|max:200',
            'is_active'     => 'nullable|boolean',
            // Khoá cổng + cách cấp mã theo hợp đồng — xem ContractTtlockService. gate_lock_ids: [] = gỡ hết.
            'gate_lock_ids'   => 'nullable|array',
            'gate_lock_ids.*' => 'integer',
            'gate_code_mode'  => 'nullable|in:' . TtlockSetting::GATE_CODE_SHARED . ',' . TtlockSetting::GATE_CODE_SEPARATE,
            'issue_mode'      => 'nullable|in:' . TtlockSetting::ISSUE_ON_CONTRACT . ',' . TtlockSetting::ISSUE_ON_PAYMENT,
        ]);

        $buildingId = (int) $data['building_id'];

        if (! $this->isBuildingAllowed($request, $buildingId)) {
            return response()->json(['message' => 'Không có quyền cấu hình TTLock cho Toà nhà này.'], 403);
        }

        $setting = TtlockSetting::forBuilding($buildingId);

        foreach (['client_id', 'username', 'api_base'] as $field) {
            if ($request->has($field) && filled($data[$field] ?? null)) {
                $setting->{$field} = $data[$field];
            }
        }
        if (filled($data['client_secret'] ?? null)) {
            $setting->client_secret = $data['client_secret'];
        }
        if (filled($data['password'] ?? null)) {
            $setting->password_md5 = ManageTtlockSettings::normalizePassword($data['password']);
        }
        if ($request->has('is_active')) {
            $setting->is_active = (bool) $data['is_active'];
        }
        if ($request->has('gate_lock_ids')) {
            $gateLockIds = array_values(array_unique(array_map('intval', $data['gate_lock_ids'] ?? [])));

            // Chỉ cho chọn khoá THUỘC tài khoản TTLock của Toà nhà (chặn lockId bất kỳ) — cùng nguyên tắc
            // RoomLockController::update(). Phải lưu tài khoản trước rồi mới chọn được khoá cổng.
            if ($gateLockIds) {
                $owned = collect(TTLockService::forBuilding($buildingId)?->getLockList() ?? [])->pluck('lockId')->map(fn ($v) => (int) $v)->all();

                if (array_diff($gateLockIds, $owned)) {
                    return response()->json(['message' => 'Khoá cổng không thuộc tài khoản TTLock của Toà nhà này (lưu tài khoản TTLock trước rồi mới chọn khoá cổng).'], 422);
                }
            }

            $setting->gate_lock_ids = $gateLockIds;
        }
        foreach (['gate_code_mode', 'issue_mode'] as $field) {
            if (filled($data[$field] ?? null)) {
                $setting->{$field} = $data[$field];
            }
        }

        if (! $setting->exists && ! $setting->isConfigured()) {
            return response()->json([
                'message' => 'Lần đầu cấu hình cần đủ client_id, client_secret, username, password.',
            ], 422);
        }

        $gateLocksChanged = $setting->isDirty('gate_lock_ids');

        $setting->building_id = $buildingId;
        $setting->save();

        TTLockService::forBuilding($buildingId)?->clearTokenCache();

        // Đổi khoá cổng -> cấp/thu hồi mã cổng cho mọi hợp đồng đang hiệu lực, chạy sau khi trả response
        // (mỗi hợp đồng là 1+ lần gọi TTLock) — cùng cách ManageTtlockSettings::save().
        if ($gateLocksChanged) {
            dispatch(fn () => ContractTtlockService::syncForBuilding($buildingId))->afterResponse();
        }

        return response()->json(['data' => $this->transform($buildingId, $setting->fresh())]);
    }

    // DELETE /api/admin/minihouse/ttlock-settings?building_id=
    public function destroy(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền xoá cấu hình TTLock.'], 403);
        }

        $buildingId = $request->integer('building_id');

        if (! $buildingId || ! $this->isBuildingAllowed($request, $buildingId)) {
            return response()->json(['message' => 'Thiếu building_id hoặc không có quyền trên Toà nhà này.'], 422);
        }

        TTLockService::forBuilding($buildingId)?->clearTokenCache();
        TtlockSetting::query()->find($buildingId)?->delete();

        return response()->json(['message' => 'Đã xoá cấu hình TTLock của Toà nhà.']);
    }

    // POST /api/admin/minihouse/ttlock-settings/test  { building_id } — thử đăng nhập bằng cấu hình đã lưu.
    public function test(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền kiểm tra kết nối TTLock.'], 403);
        }

        $buildingId = (int) $request->validate(['building_id' => 'required|integer'])['building_id'];

        if (! $this->isBuildingAllowed($request, $buildingId)) {
            return response()->json(['message' => 'Không có quyền trên Toà nhà này.'], 403);
        }

        $ttlock = TTLockService::forBuilding($buildingId);

        if (! $ttlock) {
            return response()->json(['success' => false, 'message' => 'Toà nhà chưa lưu đủ thông tin TTLock hoặc đang tắt.'], 422);
        }

        $ttlock->clearTokenCache();

        if (! $ttlock->fetchNewToken()) {
            return response()->json(['success' => false, 'message' => 'Kết nối TTLock thất bại — kiểm tra lại Client ID/Secret, tài khoản và mật khẩu.'], 422);
        }

        return response()->json(['success' => true, 'message' => 'Kết nối TTLock thành công.']);
    }

    private function authorized(Request $request): bool
    {
        return $this->hasPermission($request, 'page_manage_ttlock_settings');
    }

    /** @return array<string, mixed> */
    private function transform(int $buildingId, TtlockSetting $setting): array
    {
        return [
            'building_id'       => $buildingId,
            'client_id'         => $setting->client_id,
            'username'          => $setting->username,
            'api_base'          => $setting->api_base ?: 'https://euapi.ttlock.com',
            'is_active'         => $setting->exists ? (bool) $setting->is_active : true,
            'has_client_secret' => filled($setting->client_secret),
            'has_password'      => filled($setting->password_md5),
            'is_configured'     => $setting->isConfigured(),
            'gate_lock_ids'     => $setting->gateLockIds(),
            'gate_code_mode'    => $setting->gate_code_mode ?: TtlockSetting::GATE_CODE_SHARED,
            'issue_mode'        => $setting->issue_mode ?: TtlockSetting::ISSUE_ON_CONTRACT,
        ];
    }
}
