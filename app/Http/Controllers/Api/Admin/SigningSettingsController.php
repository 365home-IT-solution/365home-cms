<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ContractSigning\ContractSigningManager;
use App\Settings\SigningSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cách nền tảng (Bên A) ký hợp đồng Homestay — cùng dữ liệu với trang web Cấu hình web › Chữ ký số — chỉ Super Admin.
 * auto (mặc định): ký tự động qua nhà cung cấp chữ ký số từ xa (MISA eSign, VNPT SmartCA...). usb: ký bằng USB token rồi tải bản đã ký lên.
 */
class SigningSettingsController extends Controller
{
    // GET /api/admin/signing-settings
    public function show(Request $request): JsonResponse
    {
        $this->superAdmin($request);

        return response()->json(['data' => $this->payload()]);
    }

    // PUT|PATCH /api/admin/signing-settings — body: platform_sign_method = usb|auto
    public function update(Request $request): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate(['platform_sign_method' => ['required', Rule::in([ContractSigningManager::PLATFORM_SIGN_USB, ContractSigningManager::PLATFORM_SIGN_AUTO])]]);

        $settings = app(SigningSettings::class);
        $settings->platform_sign_method = $data['platform_sign_method'];
        $settings->save();

        return response()->json(['message' => 'Đã lưu cách ký của nền tảng.', 'data' => $this->payload()]);
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }

    private function payload(): array
    {
        return [
            'platform_sign_method' => app(ContractSigningManager::class)->platformSignMethod(),
            'options' => [
                ['value' => ContractSigningManager::PLATFORM_SIGN_AUTO, 'label' => 'Ký tự động qua nhà cung cấp chữ ký số từ xa'],
                ['value' => ContractSigningManager::PLATFORM_SIGN_USB, 'label' => 'Ký bằng USB token'],
            ],
        ];
    }
}
