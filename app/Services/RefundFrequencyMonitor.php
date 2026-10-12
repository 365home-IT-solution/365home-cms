<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use Illuminate\Support\Facades\Cache;
use Modules\Payment\Entities\Order;

// Hợp đồng mẫu Điều 6.4: lỗi từ phía Bên B dẫn đến hoàn tiền từ 05 lần trở lên trong 01 tháng thì Bên A có quyền đơn phương chấm dứt hợp đồng mà không phải
// bồi thường. Hệ thống KHÔNG tự chấm dứt: chỉ báo Super Admin (một lần cho mỗi tháng) để quyết định.
class RefundFrequencyMonitor
{
    public const MONTH_LIMIT = 5;

    /**
     * Số lần hoàn tiền do lỗi Bên B trong tháng dương lịch hiện tại.
     *
     * @return array{month: int}
     */
    public function counts(Partner $partner, ?\Carbon\CarbonInterface $at = null): array
    {
        $at = $at ?? now();
        $base = Order::withoutGlobalScopes()->where('partner_id', $partner->id)->where('status', 'refunded')
            ->where(fn ($q) => $q->whereNull('refund_fault')->orWhere('refund_fault', '!=', 'platform'));

        return [
            'month' => (int) (clone $base)->whereBetween('refunded_at', [$at->copy()->startOfMonth(), $at->copy()->endOfMonth()])->count(),
        ];
    }

    /**
     * Gọi sau mỗi lần hoàn tiền; trả về các ngưỡng vừa vượt (để test/ghi log).
     *
     * @return list<string>
     */
    public function check(?Partner $partner): array
    {
        if (! $partner || $partner->isMinihouse()) {
            return [];
        }

        $counts = $this->counts($partner);
        $hit = [];
        $name = $partner->legal_name ?: $partner->name;
        $escrow = app(EscrowService::class);

        if ($counts['month'] >= self::MONTH_LIMIT && Cache::add("refund-freq:{$partner->id}:m:" . now()->format('Ym'), 1, now()->addDays(40))) {
            $hit[] = 'month';
            $escrow->notifySuperAdmins('refund_frequency', 'Đối tác vượt ngưỡng hoàn tiền trong tháng', "{$name}: {$counts['month']} lần hoàn tiền do lỗi cơ sở trong tháng (từ "
                . self::MONTH_LIMIT . ' lần trong một tháng, Bên A có quyền đơn phương chấm dứt hợp đồng theo Điều 6.4).', $partner);
        }

        return $hit;
    }
}
