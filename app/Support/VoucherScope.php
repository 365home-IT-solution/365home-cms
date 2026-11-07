<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\VoucherScopeSettings;
use Illuminate\Support\Facades\Log;
use Modules\Category\Entities\Category;
use Modules\Promotion\App\Models\Coupon;

// Gắn phạm vi đối tác / chi nhánh (App\Settings\VoucherScopeSettings) vào voucher hệ thống vừa tặng — đối tác chưa đồng ý thì không được
// nhận voucher của 365home trên phòng của họ. Có chọn chi nhánh: gắn chi nhánh vào mã (Coupon::passesBranchRestriction, gồm khu vực con).
// Chỉ chọn đối tác: lưu allowed_partner_ids (Coupon::passesPartnerRestriction, so với đối tác sở hữu phòng) — áp cả phòng chưa gắn chi
// nhánh và chi nhánh mở sau.
class VoucherScope
{
    public const CHECKIN  = 'checkin';
    public const MINIGAME = 'minigame';

    public static function restrict(Coupon $coupon, string $scope): void
    {
        $settings    = app(VoucherScopeSettings::class);
        $partnerIds  = array_values(array_filter($settings->{"{$scope}_partner_ids"} ?? []));
        $categoryIds = $settings->{"{$scope}_category_ids"} ?? [];

        if ($categoryIds !== []) {
            $ids = Category::query()->withoutGlobalScopes()->whereIn('id', $categoryIds)->pluck('id')->all();

            if ($ids !== []) {
                $coupon->categories()->sync($ids);

                return;
            }
        }

        if ($partnerIds !== []) {
            $coupon->update(['allowed_partner_ids' => $partnerIds]);

            return;
        }

        Log::warning("Voucher tự tặng ({$scope}): chưa cấu hình đối tác / chi nhánh áp dụng — mã dùng được ở mọi nơi", ['coupon_id' => $coupon->id]);
    }

    public static function isConfigured(string $scope): bool
    {
        $settings = app(VoucherScopeSettings::class);

        return ($settings->{"{$scope}_partner_ids"} ?? []) !== [] || ($settings->{"{$scope}_category_ids"} ?? []) !== [];
    }
}
