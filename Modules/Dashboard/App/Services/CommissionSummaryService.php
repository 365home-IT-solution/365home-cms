<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use App\Models\Partner;
use Illuminate\Support\Facades\DB;
use Modules\Payment\Entities\Order;

// Hoa hồng super_admin nhận được từ từng đối tác trong tháng — dựa vào doanh thu (đơn đã thanh
// toán) của đối tác trong tháng × commission_rate (đã khai báo ở tab "Hợp đồng" trong hồ sơ đối
// tác — PartnerResource). super_admin xem được của TẤT CẢ đối tác (getData()); mỗi đối tác chỉ
// xem được số tiền CỦA CHÍNH MÌNH phải trả (getDataForPartner()).
class CommissionSummaryService
{
    public static function getData(?int $month = null, ?int $year = null): array
    {
        [$month, $year] = self::resolvePeriod($month, $year);

        $partners = Partner::where('verification_status', 'approved')->get();

        $rows = $partners
            ->map(fn (Partner $partner) => self::computeRow($partner, $month, $year))
            ->filter(fn (array $row) => $row['revenue'] > 0)
            ->sortByDesc('commission')
            ->values();

        return [
            'rows'             => $rows->toArray(),
            'total_revenue'    => $rows->sum('revenue'),
            'total_commission' => $rows->sum('commission'),
            'month'            => $month,
            'year'             => $year,
        ];
    }

    // Dùng cho chủ đối tác xem trên Dashboard của chính mình — CHỈ số tiền của đối tác đó, không
    // thấy được số liệu của đối tác khác (khác hẳn getData() dành cho super_admin).
    public static function getDataForPartner(string $partnerId, ?int $month = null, ?int $year = null): array
    {
        [$month, $year] = self::resolvePeriod($month, $year);

        $partner = Partner::find($partnerId);

        if (! $partner) {
            return [
                'rows'             => [],
                'total_revenue'    => 0,
                'total_commission' => 0,
                'month'            => $month,
                'year'             => $year,
            ];
        }

        $row = self::computeRow($partner, $month, $year);

        return [
            'rows'             => [$row],
            'total_revenue'    => $row['revenue'],
            'total_commission' => $row['commission'],
            'month'            => $month,
            'year'             => $year,
        ];
    }

    private static function computeRow(Partner $partner, int $month, int $year): array
    {
        $revenue = (float) Order::where('partner_id', $partner->id)
            ->where('status', 'paid')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            // 'amount' nay là tổng tiền THỰC THU cuối cùng (full_amount + phụ phí phát sinh nếu
            // có) — 'full_amount' chỉ là tổng giá gốc CỐ ĐỊNH lúc đặt, không phản ánh phụ phí phát
            // sinh sau này. Ưu tiên 'amount', chỉ fallback full_amount nếu amount rỗng (dữ liệu cũ).
            ->sum(DB::raw('COALESCE(amount, full_amount)'));

        $rate       = self::parseRate($partner->commission_rate);
        $commission = round($revenue * $rate / 100);

        return [
            'partner_name' => $partner->name,
            'revenue'      => $revenue,
            'rate'         => $rate,
            'commission'   => $commission,
        ];
    }

    private static function resolvePeriod(?int $month, ?int $year): array
    {
        return [$month ?? now()->month, $year ?? now()->year];
    }

    // commission_rate lưu dạng chuỗi tự do (vd "10", "10%", "10 %") — chỉ lấy phần số.
    private static function parseRate(?string $rate): float
    {
        if (blank($rate)) {
            return 0.0;
        }

        return (float) preg_replace('/[^0-9.]/', '', $rate);
    }
}
