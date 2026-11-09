<?php

namespace App\Console\Commands;

use App\Support\VoucherScope;
use Illuminate\Console\Command;
use Modules\Promotion\App\Models\Coupon;

/**
 * Gắn "Phạm vi voucher tự tặng" (App\Settings\VoucherScopeSettings) cho voucher hệ thống ĐÃ tặng trước khi có cài đặt — còn hạn, chưa
 * dùng hết, chưa giới hạn đối tác / chi nhánh. Mã mới tặng tự gắn lúc tạo (App\Support\VoucherScope), không cần lệnh này.
 * Chạy 1 lần sau khi Super Admin lưu cài đặt; --dry-run để xem trước.
 */
class ApplyVoucherScopeCommand extends Command
{
    protected $signature   = 'vouchers:apply-scope {--dry-run : Chỉ liệt kê, không đổi gì}';
    protected $description = 'Gắn phạm vi đối tác / chi nhánh đã cài cho voucher điểm danh & mini game đã tặng mà chưa giới hạn';

    private const MINIGAME_SOURCES = ['scratch_card', 'collection', 'referral', 'game_points', 'caro'];

    public function handle(): int
    {
        $coupons = Coupon::withoutGlobalScopes()
            ->whereNull('partner_id')
            ->whereNotNull('customer_id')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->where(fn ($q) => $q->whereNotNull('auto_issue_tier_id')->orWhereIn('source', self::MINIGAME_SOURCES))
            ->whereNull('allowed_partner_ids')
            ->doesntHave('categories')
            ->orderBy('created_at')
            ->get();

        if ($coupons->isEmpty()) {
            $this->info('Không có voucher nào cần gắn phạm vi.');

            return self::SUCCESS;
        }

        $rows    = [];
        $applied = 0;

        foreach ($coupons as $coupon) {
            $scope = $coupon->auto_issue_tier_id ? VoucherScope::CHECKIN : VoucherScope::MINIGAME;

            if (! VoucherScope::isConfigured($scope)) {
                $rows[] = [$coupon->code, $coupon->name, $scope, 'BỎ QUA — chưa cài phạm vi'];

                continue;
            }

            if (! $this->option('dry-run')) {
                VoucherScope::restrict($coupon, $scope);
                $coupon->refresh();
                $applied++;
            }

            $rows[] = [$coupon->code, $coupon->name, $scope, $this->option('dry-run') ? 'sẽ gắn' : $this->describe($coupon)];
        }

        $this->table(['Mã', 'Tên', 'Loại', 'Kết quả'], $rows);
        $this->info($this->option('dry-run')
            ? "Xem trước: {$coupons->count()} voucher. Chạy lại không có --dry-run để gắn."
            : "Đã gắn phạm vi cho {$applied}/{$coupons->count()} voucher.");

        return self::SUCCESS;
    }

    private function describe(Coupon $coupon): string
    {
        $branches = $coupon->categories()->pluck('categories.name');
        if ($branches->isNotEmpty()) {
            return 'chi nhánh: ' . $branches->implode(', ');
        }

        if (! empty($coupon->allowed_partner_ids)) {
            return 'đối tác: ' . \App\Models\Partner::withoutGlobalScopes()->whereIn('id', $coupon->allowed_partner_ids)->pluck('name')->implode(', ');
        }

        return 'không giới hạn';
    }
}
