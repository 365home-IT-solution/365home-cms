<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralSetting;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Quản trị giới thiệu bạn bè (chỉ Super Admin) — xem App\Services\ReferralService.
 *
 *  - GET|PUT /api/admin/games/referral/settings         bật/tắt, voucher chào mừng, giới hạn thưởng / tháng, số ngày được nhập mã, link chia sẻ
 *  - GET /api/admin/games/referral/referrals?status=&search=&per_page=   danh sách cặp giới thiệu (lọc invalid để soát gian lận)
 *  - POST /api/admin/games/referral/referrals/{id}/invalidate            huỷ cặp nghi gian lận (một chiều), thu hồi voucher/thẻ chưa dùng
 */
class ReferralAdminController extends Controller
{
    public function __construct(private readonly ReferralService $service) {}

    public function showSettings(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => ReferralSetting::current()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $data = $request->validate([
            'is_enabled'      => 'sometimes|boolean',
            'coupon_type'     => ['sometimes', Rule::in(['fixed', 'percentage'])],
            'coupon_value'    => 'sometimes|numeric|min:1',
            'min_order_value' => 'nullable|numeric|min:0',
            'max_discount'    => 'nullable|numeric|min:0',
            'validity_days'   => 'sometimes|integer|min:1|max:365',
            'monthly_limit'   => 'sometimes|integer|min:1|max:1000',
            'apply_days'      => 'sometimes|integer|min:1|max:90',
            'share_url_base'  => 'sometimes|url|max:255',
        ]);

        $setting = ReferralSetting::current();

        if (($data['coupon_type'] ?? $setting->coupon_type) === 'percentage' && (float) ($data['coupon_value'] ?? $setting->coupon_value) > 100) {
            throw \Illuminate\Validation\ValidationException::withMessages(['coupon_value' => ['Giảm theo phần trăm không được quá 100.']]);
        }

        $setting->update($data);

        return response()->json(['data' => $setting->fresh()]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $request->validate(['status' => 'sometimes|nullable|in:joined,rewarded,invalid']);
        $search = trim((string) $request->input('search'));

        $page = Referral::with(['referrer', 'referee', 'welcomeCoupon'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('code', 'like', "%{$search}%")
                ->orWhereHas('referrer', fn ($c) => $c->where('fullname', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                ->orWhereHas('referee', fn ($c) => $c->where('fullname', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->orderByDesc('created_at')
            ->paginate(min(100, max(1, (int) $request->input('per_page', 20))));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Referral $r) => $this->row($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function invalidate(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $data = $request->validate(['reason' => 'nullable|string|max:40']);

        $referral = $this->service->invalidate(Referral::with(['referrer', 'referee', 'welcomeCoupon'])->findOrFail($id), $data['reason'] ?? null);

        return response()->json(['data' => $this->row($referral->load(['referrer', 'referee', 'welcomeCoupon']))]);
    }

    private function row(Referral $r): array
    {
        return [
            'id'                  => $r->id,
            'code'                => $r->code,
            'status'              => $r->status,
            'reason'              => $r->reason,
            'referrer'            => ['id' => $r->referrer_id, 'name' => $r->referrer?->fullname, 'phone' => $r->referrer?->phone],
            'referee'             => ['id' => $r->referee_id, 'name' => $r->referee?->fullname, 'phone' => $r->referee?->phone],
            'device_id'           => $r->device_id,
            'welcome_coupon_code' => $r->welcomeCoupon?->code,
            'welcome_coupon_used' => $r->welcomeCoupon ? (int) $r->welcomeCoupon->used_count > 0 : false,
            'first_order_id'      => $r->first_order_id,
            'scratch_card_id'     => $r->scratch_card_id,
            'rewarded_at'         => $r->rewarded_at?->toDateTimeString(),
            'created_at'          => $r->created_at?->toDateTimeString(),
        ];
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
