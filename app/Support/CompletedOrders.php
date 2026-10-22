<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\OrderCommissionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;

/**
 * "Đơn hoàn tất" cho các mini game (thẻ cào, mảnh ghép, giới thiệu) — CÙNG định nghĩa với chốt hoa hồng (OrderCommissionService):
 * đã trả đủ VÀ (đã bấm/được đánh dấu trả phòng HOẶC kỳ lưu trú đã kết thúc quá settlement.auto_complete_hours giờ).
 * Phần lớn đơn thật không bao giờ được đánh dấu checked_out (khách không mở khoá lần 2, lễ tân không bấm), nên chỉ nhìn order_status
 * sẽ bỏ sót đa số lượt chơi.
 */
class CompletedOrders
{
    public static function isCompleted(Order $order): bool
    {
        if ($order->status !== 'paid') {
            return false;
        }

        return $order->order_status === 'checked_out' || app(OrderCommissionService::class)->stayEnded($order);
    }

    // Thời điểm hoàn tất: lúc trả phòng thực tế, không có thì giờ trả phòng cuối của đơn.
    public static function completedAt(Order $order): Carbon
    {
        if ($order->checked_out_at) {
            return Carbon::parse($order->checked_out_at);
        }

        $last = OrderItem::where('order_id', $order->id)->max('checkout_date');

        return $last ? Carbon::parse($last) : now();
    }

    /**
     * Đơn của khách đã đăng nhập vừa hoàn tất trong $days ngày gần đây — dùng cho lệnh quét bù (hook ở Order không bắt được đơn
     * "tự hoàn tất" khi hết giờ lưu trú vì không có sự kiện nào xảy ra).
     */
    public static function recent(int $days): Builder
    {
        $grace = (int) config('settlement.auto_complete_hours', 12);

        return Order::withoutGlobalScopes()
            ->where('status', 'paid')
            ->whereNotNull('customer_id')
            ->where(function (Builder $q) use ($days, $grace) {
                $q->where(fn (Builder $c) => $c->where('order_status', 'checked_out')->whereRaw('COALESCE(checked_out_at, updated_at) >= ?', [now()->subDays($days)]))
                    ->orWhereIn('id', OrderItem::query()->select('order_id')->groupBy('order_id')
                        ->havingRaw('MAX(checkout_date) < ? AND MAX(checkout_date) >= ?', [now()->subHours($grace), now()->subDays($days)->subHours($grace)]));
            });
    }
}
