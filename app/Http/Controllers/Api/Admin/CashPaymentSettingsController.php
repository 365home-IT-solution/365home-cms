<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\Payment\CancellationGuardService;
use App\Settings\CashPaymentSettings;
use App\Support\CashPaymentConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Tiền mặt tại quầy qua API — cùng chức năng với trang CMS "Tiền mặt tại quầy — cấu hình" và nút mở khoá ở trang sửa đối tác. Chỉ Super Admin (trừ xem trạng thái khoá
// của chính đối tác mình). Cấu hình: trường không gửi = giữ nguyên; gửi null = bỏ giá trị CMS, quay về .env.
class CashPaymentSettingsController extends Controller
{
    public function __construct(private readonly CancellationGuardService $guard)
    {
    }

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        return response()->json(['data' => $this->status()]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        $data = $request->validate([
            'no_show_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:72'],
            'guard_enabled' => ['sometimes', 'nullable', 'boolean'],
            'partner_day_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'partner_month_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'customer_day_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'customer_month_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $settings = app(CashPaymentSettings::class);
        foreach (CashPaymentConfig::KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $settings->{$key} = $data[$key] === null ? null : ($key === 'guard_enabled' ? (bool) $data[$key] : (int) $data[$key]);
            }
        }
        $settings->save();
        app()->forgetInstance(CashPaymentSettings::class);

        return response()->json(['message' => 'Đã lưu cấu hình tiền mặt tại quầy.', 'data' => $this->status()]);
    }

    /** GET /api/admin/partners/{partner}/cash-cancel-lock — trạng thái khoá phòng do huỷ đơn tiền mặt cao + số đơn huỷ hôm nay / tháng này. */
    public function partnerLock(Request $request, Partner $partner): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->partner_id === $partner->id, 403);

        return response()->json(['data' => $this->guard->partnerStatus($partner)]);
    }

    /** POST /api/admin/partners/{partner}/cash-cancel-lock/release — Super Admin mở khoá: các phòng đã đăng lúc khoá được mở lại. */
    public function releasePartnerLock(Request $request, Partner $partner): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        if (! $partner->cancel_locked_at) {
            return response()->json(['message' => 'Đối tác không bị khoá phòng.'], 422);
        }

        $reopened = $this->guard->release($partner, $request->user());

        return response()->json(['message' => 'Đã mở khoá — mở lại ' . $reopened . ' phòng.', 'data' => $this->guard->partnerStatus($partner->fresh())]);
    }

    private function status(): array
    {
        $defaults = ['no_show_hours' => 3, 'guard_enabled' => true, 'partner_day_limit' => 3, 'partner_month_limit' => 15, 'customer_day_limit' => 3, 'customer_month_limit' => 15];

        return collect($defaults)->mapWithKeys(fn ($default, string $key) => [$key => [
            'value' => $key === 'guard_enabled' ? (bool) CashPaymentConfig::get($key, $default) : (int) CashPaymentConfig::get($key, $default),
            'source' => CashPaymentConfig::source($key),
        ]])->all();
    }
}
