<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CouponPartnerParticipation;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Promotion\App\Models\Coupon;

/**
 * Chiến dịch ĐỒNG TÀI TRỢ của 365home (coupon funded_by = shared, không thuộc đối tác nào): đối tác phải đồng ý tham gia
 * theo TỪNG CHI NHÁNH thì mã mới áp lên phòng của chi nhánh đó (Coupon::appliesToRoom()). Mặc định chưa tham gia.
 * Mã do 365home chịu 100% hay do đối tác tự tạo không cần đồng ý — không làm đối tác thiệt.
 */
class PartnerCouponCampaignController extends Controller
{
    // GET …/coupon-campaigns — các chiến dịch đồng tài trợ đang chạy và trạng thái tham gia của từng chi nhánh.
    public function index(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);

        $branches = $this->branches($partner);
        $joined = CouponPartnerParticipation::query()->where('partner_id', $partner->id)->get()->groupBy('coupon_id');

        $campaigns = Coupon::withoutGlobalScopes()->where('funded_by', Coupon::FUNDED_SHARED)->whereNull('partner_id')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', now()))->orderByDesc('id')->get();

        return response()->json(['data' => $campaigns->map(fn (Coupon $coupon) => [
            'id'                => $coupon->id,
            'code'              => $coupon->code,
            'name'              => $coupon->name,
            'type'              => $coupon->type,
            'value'             => $coupon->value,
            'start_at'          => $coupon->start_at?->toIso8601String(),
            'end_at'            => $coupon->end_at?->toIso8601String(),
            // Đối tác chịu bao nhiêu % tiền giảm khi tham gia (365home chịu phần còn lại).
            'partner_share_pct' => $coupon->partner_share_pct,
            'branches'          => $branches->map(fn ($branch) => [
                'category_id' => $branch->id,
                'name'        => $branch->name,
                'is_enabled'  => (bool) optional(($joined[$coupon->id] ?? collect())->firstWhere('category_id', $branch->id))->is_enabled,
            ])->values(),
        ])->values()]);
    }

    // PUT …/coupon-campaigns/{coupon}/participation — chủ đối tác bật/tắt tham gia theo chi nhánh. body: category_id, is_enabled
    public function update(Request $request, Partner $partner, int $coupon): JsonResponse
    {
        $this->authorizeRespond($request, $partner);
        $data = $request->validate(['category_id' => ['required', 'integer'], 'is_enabled' => ['required', 'boolean']]);

        $campaign = Coupon::withoutGlobalScopes()->where('funded_by', Coupon::FUNDED_SHARED)->whereNull('partner_id')->findOrFail($coupon);
        abort_unless($this->branches($partner)->contains('id', (int) $data['category_id']), 422, 'Chi nhánh không thuộc đối tác này.');

        CouponPartnerParticipation::updateOrCreate(
            ['coupon_id' => $campaign->id, 'category_id' => (int) $data['category_id']],
            ['partner_id' => $partner->id, 'is_enabled' => (bool) $data['is_enabled'], 'decided_by' => $request->user()->id, 'decided_at' => now()],
        );

        return response()->json(['message' => $data['is_enabled'] ? 'Đã tham gia chiến dịch cho chi nhánh này.' : 'Đã tắt tham gia chiến dịch cho chi nhánh này.']);
    }

    private function branches(Partner $partner)
    {
        return $partner->categories()->whereNull('parent_id')->orderBy('name')->get(['id', 'name']);
    }

    private function authorizeView(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->partner_id === $partner->id, 403);
    }

    private function authorizeRespond(Request $request, Partner $partner): void
    {
        $this->authorizeView($request, $partner);
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->hasRole('partner') || $user->can('update_partner'), 403, 'Chỉ chủ đối tác được quyết định tham gia chiến dịch.');
    }
}
