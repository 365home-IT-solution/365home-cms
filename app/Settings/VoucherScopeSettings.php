<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Đối tác / chi nhánh được dùng voucher do hệ thống tự tặng — Super Admin chỉnh ở trang "Phạm vi voucher tự tặng"
// (App\Filament\Pages\VoucherScopeSettingsPage). Xem App\Support\VoucherScope.
//   checkin_*  : mã điểm danh đủ chu kỳ (MembershipService::grantCheckinReward)
//   minigame_* : voucher mọi mini game — thẻ cào, mảnh ghép, giới thiệu bạn, đổi điểm game, caro (giải caro có chi nhánh riêng thì theo giải)
// *_partner_ids: mọi chi nhánh của các đối tác này; *_category_ids: thu hẹp về đúng các chi nhánh này.
class VoucherScopeSettings extends Settings
{
    /** @var array<int, string> */
    public array $checkin_partner_ids;

    /** @var array<int, int> */
    public array $checkin_category_ids;

    /** @var array<int, string> */
    public array $minigame_partner_ids;

    /** @var array<int, int> */
    public array $minigame_category_ids;

    public static function group(): string
    {
        return 'voucher_scope';
    }
}
