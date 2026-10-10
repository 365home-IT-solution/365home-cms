<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Province;
use App\Services\PartnerTrialService;
use App\Settings\EscrowSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cấu hình MIỄN PHÍ THÁNG ĐẦU của ký quỹ cho app (cùng dữ liệu với trang web Cấu hình web › Ký quỹ) — chỉ Super Admin.
 * Công tắc, số tháng, các tỉnh phải ký quỹ ngay và mốc nhắc đều đổi ở đây, không phải sửa code (xem App\Services\PartnerTrialService).
 */
class EscrowSettingsController extends Controller
{
    /** Mốc nhắc hợp lệ (số ngày trước khi hết miễn phí). */
    public const REMINDER_OPTIONS = [14, 7, 3, 1];

    public function __construct(private PartnerTrialService $trial) {}

    // GET /api/admin/escrow-settings
    public function show(Request $request): JsonResponse
    {
        $this->superAdmin($request);

        return response()->json(['data' => $this->payload(app(EscrowSettings::class))]);
    }

    // PUT|PATCH /api/admin/escrow-settings — body (đều tuỳ chọn, gửi trường nào đổi trường đó): free_trial_enabled, free_trial_months, immediate_province_codes[], reminder_days[]
    public function update(Request $request): JsonResponse
    {
        $this->superAdmin($request);

        $data = $request->validate([
            'free_trial_enabled'         => ['sometimes', 'boolean'],
            'free_trial_months'          => ['sometimes', 'integer', 'min:1', 'max:12'],
            'immediate_province_codes'   => ['sometimes', 'array'],
            'immediate_province_codes.*' => ['integer', 'distinct', Rule::exists(Province::class, 'code')],
            'reminder_days'              => ['sometimes', 'array'],
            'reminder_days.*'            => ['integer', 'distinct', Rule::in(self::REMINDER_OPTIONS)],
        ]);

        $settings = app(EscrowSettings::class);

        foreach ($data as $key => $value) {
            $settings->{$key} = match ($key) {
                'free_trial_enabled'       => (bool) $value,
                'free_trial_months'        => (int) $value,
                default                    => array_values(array_map('intval', (array) $value)),
            };
        }

        $settings->save();

        return response()->json(['message' => 'Đã lưu cấu hình miễn phí tháng đầu.', 'data' => $this->payload($settings)]);
    }

    // GET /api/admin/partners/{partner}/free-trial — đối tác này có được miễn phí tháng đầu không, vì sao, và đang miễn đến khi nào
    public function partnerStatus(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);

        return response()->json(['data' => $this->trial->status($partner)]);
    }

    /** @return array<string, mixed> */
    private function payload(EscrowSettings $settings): array
    {
        $names = Province::query()->whereNotNull('code')->orderBy('name')->pluck('name', 'code');
        $immediate = array_map('intval', $settings->immediate_province_codes);

        return [
            'free_trial_enabled'       => (bool) $settings->free_trial_enabled,
            'free_trial_months'        => (int) $settings->free_trial_months,
            'immediate_province_codes' => $immediate,
            'immediate_provinces'      => collect($immediate)->map(fn (int $code) => ['code' => $code, 'name' => $names[$code] ?? null])->values(),
            'reminder_days'            => array_map('intval', $settings->reminder_days),
            // Danh mục để dựng form: các mốc nhắc cho chọn và danh sách tỉnh/thành (mã dùng làm province_code của đối tác).
            'reminder_day_options'     => self::REMINDER_OPTIONS,
            'province_options'         => $names->map(fn (string $name, int|string $code) => ['code' => (int) $code, 'name' => $name])->values(),
        ];
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
