<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\MembershipTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Promotion\App\Models\Coupon;

class MembershipController extends Controller
{
    /**
     * GET /api/membership
     *
     * Trả về hạng thành viên hiện tại, tổng chi tiêu,
     * danh sách coupon cá nhân còn dùng được, và hạng kế tiếp.
     */
    public function show(Request $request): JsonResponse
    {
        $customer = $request->user();
        $customer->loadMissing('membershipTier');

        $tier     = $customer->membershipTier;
        $spending = (float) $customer->total_spending;

        $nextTier = MembershipTier::where('is_active', true)
            ->where('min_spending', '>', $spending)
            ->orderBy('min_spending')
            ->first();

        $coupons = Coupon::where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id)
                  ->orWhereHas('customers', fn ($q2) => $q2->where('cms_customers.id', $customer->id));
            })
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->orderByDesc('created_at')
            ->get(['id', 'code', 'name', 'type', 'value', 'max_discount', 'min_order_value', 'end_at']);

        return response()->json([
            'membership' => [
                'tier' => $tier ? [
                    'id'    => $tier->id,
                    'name'  => $tier->name,
                    'slug'  => $tier->slug,
                    'color' => $tier->color,
                    'icon'  => $tier->icon,
                ] : null,
                'total_spending' => $spending,
                'next_tier'      => $nextTier ? [
                    'id'           => $nextTier->id,
                    'name'         => $nextTier->name,
                    'min_spending' => (float) $nextTier->min_spending,
                    'remaining'    => max(0, (float) $nextTier->min_spending - $spending),
                ] : null,
                'coupons' => $coupons->map(fn (Coupon $c) => [
                    'code'            => $c->code,
                    'name'            => $c->name,
                    'type'            => $c->type,
                    'value'           => (float) $c->value,
                    'max_discount'    => $c->max_discount ? (float) $c->max_discount : null,
                    'min_order_value' => $c->min_order_value ? (float) $c->min_order_value : null,
                    'expires_at'      => $c->end_at?->toDateString(),
                ]),
            ],
        ]);
    }

    /**
     * GET /api/membership/tiers
     *
     * Danh sách tất cả hạng thành viên (công khai, không cần đăng nhập).
     */
    public function tiers(): JsonResponse
    {
        $tiers = MembershipTier::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'description', 'color', 'icon', 'min_spending',
                   'welcome_coupon_type', 'welcome_coupon_value']);

        return response()->json([
            'tiers' => $tiers->map(fn (MembershipTier $t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'slug'         => $t->slug,
                'description'  => $t->description,
                'color'        => $t->color,
                'icon'         => $t->icon,
                'min_spending' => (float) $t->min_spending,
                'coupon_reward'=> $t->welcome_coupon_value > 0 ? [
                    'type'  => $t->welcome_coupon_type,
                    'value' => (float) $t->welcome_coupon_value,
                ] : null,
            ]),
        ]);
    }
}
