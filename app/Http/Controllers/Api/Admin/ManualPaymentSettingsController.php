<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Settings\ManualPaymentSettings;
use App\Support\ManualPaymentConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Cấu hình "xác nhận thủ công" qua API — cùng chức năng với trang CMS "Xác nhận thủ công — cấu hình". Chỉ Super Admin.
// Trường không gửi = giữ nguyên; gửi null = bỏ giá trị CMS, quay về .env (MANUAL_PAYMENT_*).
class ManualPaymentSettingsController extends Controller
{
    private const KEYS = ['hold_minutes' => 60, 'claimed_hold_minutes' => 240];

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        return response()->json(['data' => $this->status()]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        $data = $request->validate([
            'hold_minutes' => ['sometimes', 'nullable', 'integer', 'min:15', 'max:1440'],
            'claimed_hold_minutes' => ['sometimes', 'nullable', 'integer', 'min:15', 'max:2880'],
        ]);

        $settings = app(ManualPaymentSettings::class);
        foreach (array_keys(self::KEYS) as $key) {
            if (array_key_exists($key, $data)) {
                $settings->{$key} = $data[$key] !== null ? (int) $data[$key] : null;
            }
        }
        $settings->save();
        app()->forgetInstance(ManualPaymentSettings::class);

        return response()->json(['message' => 'Đã lưu cấu hình xác nhận thủ công.', 'data' => $this->status()]);
    }

    private function status(): array
    {
        return collect(self::KEYS)->mapWithKeys(fn (int $default, string $key) => [
            $key => ['value' => (int) ManualPaymentConfig::get($key, $default), 'source' => ManualPaymentConfig::source($key)],
        ])->all();
    }
}
