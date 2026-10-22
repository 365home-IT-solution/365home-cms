<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\RoomRating;
use App\Models\ScratchCard;
use App\Models\ScratchCardPrize;
use App\Models\ScratchCardSetting;
use App\Models\User;
use App\Support\CompletedOrders;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Promotion\App\Models\Coupon;

/**
 * Mini game THẺ CÀO: sinh thẻ khi đơn của khách đã đăng nhập trả phòng (hoặc thưởng đánh giá kèm ảnh), BE bốc giải theo trọng số khi khách cào,
 * voucher trúng là mã cá nhân dùng chung (partner_id = null, 365home chịu) nằm trong /coupons/mine.
 * Gọi từ Order::booted (đổi trạng thái), RatingController (đánh giá có ảnh) và lệnh scratch-cards:sync (quét bù + hết hạn).
 */
class ScratchCardService
{
    public const FALLBACK_LABEL = 'Chúc bạn may mắn lần sau';

    public function issueForOrder(Order $order): ?ScratchCard
    {
        $setting = ScratchCardSetting::current();

        if (! $setting->is_enabled || ! $this->orderQualifies($order, $setting)) {
            return null;
        }

        return $this->createCard($order, 'order', $setting);
    }

    // +1 thẻ cho đơn gần nhất của khách ở phòng vừa đánh giá (đơn đã có thẻ gốc, chưa có thẻ thưởng) — chỉ khi đánh giá có ≥ 1 ảnh.
    public function issueRatingBonus(Customer $customer, RoomRating $rating): ?ScratchCard
    {
        $setting = ScratchCardSetting::current();

        if (! $setting->is_enabled || ! $setting->rating_bonus_enabled || $rating->getMedia(RoomRating::IMAGE_COLLECTION)->isEmpty()) {
            return null;
        }

        // Đánh giá là theo phòng (1 đánh giá / khách / phòng) nên chỉ thưởng cho đơn GẦN NHẤT của khách ở phòng này; sửa đánh giá nhiều lần
        // không đẩy sang đơn cũ hơn để lấy thêm thẻ (đơn đó đã có thẻ thưởng thì thôi).
        $orderId = ScratchCard::where('customer_id', $customer->id)
            ->where('source', 'order')
            ->whereNotIn('status', [ScratchCard::STATUS_VOIDED])
            ->whereIn('order_id', OrderItem::where('product_id', $rating->room_id)->select('order_id'))
            ->orderByDesc('created_at')
            ->value('order_id');

        if ($orderId && ScratchCard::where('order_id', $orderId)->where('source', 'rating_bonus')->exists()) {
            return null;
        }

        $order = $orderId ? Order::withoutGlobalScopes()->find($orderId) : null;

        return $order ? $this->createCard($order, 'rating_bonus', $setting) : null;
    }

    // Thẻ thưởng giới thiệu: gắn với đơn đầu của BẠN ĐƯỢC MỜI (unique order_id + source ⇒ mỗi đơn đó chỉ thưởng một lần; đơn bị hoàn tiền thì
    // voidForOrder thu hồi luôn thẻ này). Thông báo do ReferralService gửi (referral_reward), không dùng thông báo "thẻ cào mới".
    public function issueReferralCard(Customer $referrer, Order $refereeOrder): ?ScratchCard
    {
        $setting = ScratchCardSetting::current();

        return $setting->is_enabled ? $this->createCard($refereeOrder, 'referral', $setting, (string) $referrer->id, false) : null;
    }

    // Đơn bị huỷ/hoàn tiền: thu hồi thẻ chưa cào, vô hiệu voucher đã cào mà chưa dùng; voucher đã dùng giữ nguyên, chỉ ghi log.
    public function voidForOrder(Order $order): void
    {
        ScratchCard::where('order_id', $order->id)
            ->whereIn('status', [ScratchCard::STATUS_AVAILABLE, ScratchCard::STATUS_SCRATCHED])
            ->with('coupon')
            ->get()
            ->each(function (ScratchCard $card) use ($order) {
                $coupon = $card->coupon;

                if ($coupon && (int) $coupon->used_count > 0) {
                    Log::info('ScratchCard: voucher đã dùng, giữ nguyên dù đơn bị huỷ/hoàn tiền', ['order_id' => $order->id, 'card_id' => $card->id, 'coupon' => $coupon->code]);

                    return;
                }

                $coupon?->update(['is_active' => false]);
                $card->update(['status' => ScratchCard::STATUS_VOIDED]);
            });
    }

    // Quét bù: đơn đã trả phòng gần đây mà hook bỏ sót + đánh dấu thẻ quá hạn. Trả về [số thẻ mới, số thẻ hết hạn].
    public function sync(int $lookbackDays = 3): array
    {
        $expired = ScratchCard::where('status', ScratchCard::STATUS_AVAILABLE)->where('expires_at', '<', now())
            ->update(['status' => ScratchCard::STATUS_EXPIRED]);

        $issued = 0;

        if (ScratchCardSetting::current()->is_enabled) {
            CompletedOrders::recent($lookbackDays)
                ->whereNotIn('id', ScratchCard::where('source', 'order')->whereNotNull('order_id')->select('order_id'))
                ->each(function (Order $order) use (&$issued) {
                    if ($this->issueForOrder($order)) {
                        $issued++;
                    }
                });
        }

        return [$issued, $expired];
    }

    /**
     * Cào thẻ: bốc giải và trừ lượt trong 1 transaction có khoá để hai request cùng lúc không vượt giới hạn.
     * Cào lại thẻ đã cào trả đúng kết quả lần đầu (already_claimed = true).
     *
     * @return array{card: ScratchCard, already_claimed: bool}
     */
    public function scratch(string $cardId, Customer $customer): array
    {
        $result = DB::transaction(function () use ($cardId, $customer) {
            $card = ScratchCard::where('id', $cardId)->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();

            if ($card->status === ScratchCard::STATUS_SCRATCHED) {
                return ['card' => $card->load('coupon'), 'already_claimed' => true];
            }

            if ($card->status !== ScratchCard::STATUS_AVAILABLE) {
                throw ValidationException::withMessages(['message' => ['Thẻ đã hết hạn']])->status(422);
            }

            if ($card->expires_at && $card->expires_at->isPast()) {
                $card->update(['status' => ScratchCard::STATUS_EXPIRED]);

                return ['expired' => true]; // ném lỗi SAU transaction để trạng thái expired được lưu, không bị rollback
            }

            $prize  = $this->drawPrize();
            $coupon = $prize->type === 'coupon' ? $this->createCoupon($customer, $prize) : null;

            if ($prize->exists) {
                $prize->increment('awarded_count');
            }
            $card->update([
                'status'       => ScratchCard::STATUS_SCRATCHED,
                'scratched_at' => now(),
                'prize_id'     => $prize->id,
                'prize_label'  => $prize->label,
                'prize_type'   => $prize->type,
                'prize_tier'   => $prize->tier,
                'coupon_id'    => $coupon?->id,
            ]);

            return ['card' => $card->load('coupon'), 'already_claimed' => false];
        });

        if (isset($result['expired'])) {
            $this->expiredResponse();
        }

        return $result;
    }

    // Shape voucher giống 1 phần tử GET /coupons/mine để app dùng lại VoucherCard.
    public static function couponPayload(?Coupon $c): ?array
    {
        return $c ? [
            'code'            => $c->code,
            'name'            => $c->name,
            'description'     => $c->description,
            'type'            => $c->type,
            'value'           => $c->value,
            'apply_type'      => $c->apply_type,
            'min_order_value' => $c->min_order_value,
            'max_discount'    => $c->max_discount,
            'start_at'        => $c->start_at?->toDateTimeString(),
            'end_at'          => $c->end_at?->toDateTimeString(),
            'is_personal'     => true,
            'usage_remaining' => $c->usage_limit !== null ? max(0, (int) $c->usage_limit - (int) $c->used_count) : null,
            'source'          => $c->source,
        ] : null;
    }

    public static function cardPayload(ScratchCard $card, bool $withOrder = true): array
    {
        $scratched = $card->status === ScratchCard::STATUS_SCRATCHED;
        // Thẻ thưởng giới thiệu gắn với đơn của người khác — không lộ mã đơn / chi nhánh của bạn được mời
        $withOrder = $withOrder && $card->source !== 'referral';

        return [
            'id'           => $card->id,
            'source'       => $card->source,
            'status'       => $card->status,
            'order_code'   => $withOrder ? $card->order?->order_code : null,
            'branch_name'  => $withOrder ? $card->order?->category?->name : null,
            'created_at'   => $card->created_at?->toDateTimeString(),
            'expires_at'   => $card->expires_at?->toDateTimeString(),
            'scratched_at' => $card->scratched_at?->toDateTimeString(),
            // Không lộ giải của thẻ chưa cào
            'reward'       => $scratched ? self::rewardPayload($card) : null,
        ];
    }

    public static function rewardPayload(ScratchCard $card): array
    {
        return [
            'type'   => $card->prize_type,
            'label'  => $card->prize_label,
            'tier'   => $card->prize_tier,
            'coupon' => self::couponPayload($card->coupon),
            'points' => null,
        ];
    }

    private function expiredResponse(): never
    {
        throw ValidationException::withMessages(['message' => ['Thẻ đã hết hạn']])->status(422);
    }

    // Đơn của khách đã đăng nhập, đã hoàn tất (trả đủ + trả phòng / hết giờ lưu trú), đạt mức tiền và số ngày ở tối thiểu.
    private function orderQualifies(Order $order, ScratchCardSetting $setting): bool
    {
        return $order->customer_id && CompletedOrders::isCompleted($order) && $this->meetsMinimum($order, $setting);
    }

    // Điều kiện giá trị của đơn (chống đặt đơn 0đ/rẻ để lấy thưởng) — dùng chung cho thẻ cào và thưởng giới thiệu.
    public function meetsMinimum(Order $order, ?ScratchCardSetting $setting = null): bool
    {
        $setting ??= ScratchCardSetting::current();

        if ((int) ($order->full_amount ?: $order->amount) < $setting->min_order_amount) {
            return false;
        }

        if ($setting->min_stay_days <= 0) {
            return true;
        }

        $items = OrderItem::where('order_id', $order->id)->where('extra_fee', 0)->whereNotNull('checkin_date')->whereNotNull('checkout_date');
        $in    = $items->min('checkin_date');
        $out   = $items->max('checkout_date');

        if (! $in || ! $out) {
            return false;
        }

        return now()->parse($in)->startOfDay()->diffInDays(now()->parse($out)->startOfDay()) >= $setting->min_stay_days;
    }

    private function createCard(Order $order, string $source, ScratchCardSetting $setting, ?string $customerId = null, bool $notify = true): ?ScratchCard
    {
        $customerId ??= (string) $order->customer_id;

        try {
            $card = ScratchCard::firstOrCreate(
                ['order_id' => $order->id, 'source' => $source],
                ['customer_id' => $customerId, 'status' => ScratchCard::STATUS_AVAILABLE, 'expires_at' => now()->addDays($setting->expire_days)->endOfDay()],
            );
        } catch (UniqueConstraintViolationException) {
            return null; // request khác vừa sinh cùng thẻ
        }

        if (! $card->wasRecentlyCreated) {
            return null;
        }

        $customer = $notify ? Customer::find($customerId) : null;
        if ($customer) {
            try {
                app(NotificationFcmService::class)->sendToCustomer(
                    $customer, 'Bạn có thẻ cào mới', 'Cào ngay để nhận voucher cho lần đặt phòng sau!', 'scratch_card', [], '/games/scratch-cards',
                );
            } catch (\Throwable $e) {
                Log::warning('ScratchCard: gửi thông báo thất bại', ['card_id' => $card->id, 'error' => $e->getMessage()]);
            }
        }

        return $card;
    }

    // Bốc 1 giải theo trọng số trong số giải còn lượt (daily_limit / total_limit); giải dự phòng không giới hạn. Gọi trong transaction.
    private function drawPrize(): ScratchCardPrize
    {
        $prizes = ScratchCardPrize::where('is_active', true)->where('weight', '>', 0)->lockForUpdate()->get();

        $today = ScratchCard::where('status', ScratchCard::STATUS_SCRATCHED)->where('scratched_at', '>=', now()->startOfDay())
            ->whereNotNull('prize_id')->selectRaw('prize_id, COUNT(*) as c')->groupBy('prize_id')->pluck('c', 'prize_id');

        $pool = $prizes->filter(fn (ScratchCardPrize $p) => $p->is_fallback
            || (($p->daily_limit === null || (int) ($today[$p->id] ?? 0) < $p->daily_limit)
                && ($p->total_limit === null || $p->awarded_count < $p->total_limit)));

        if ($pool->isEmpty()) {
            // Chưa cấu hình giải dự phòng: không báo lỗi cho khách, trả giải trống tạm thời (không lưu DB).
            return new ScratchCardPrize(['label' => self::FALLBACK_LABEL, 'type' => 'none', 'tier' => 'normal', 'id' => null]);
        }

        $roll = random_int(1, (int) $pool->sum('weight'));
        foreach ($pool as $prize) {
            $roll -= $prize->weight;
            if ($roll <= 0) {
                return $prize;
            }
        }

        return $pool->last();
    }

    private function createCoupon(Customer $customer, ScratchCardPrize $prize): Coupon
    {
        do {
            $code = 'SC' . strtoupper(Str::random(6));
        } while (Coupon::withoutGlobalScopes()->where('code', $code)->exists());

        return Coupon::create([
            'partner_id'      => null,
            'funded_by'       => Coupon::FUNDED_PLATFORM,
            'source'          => 'scratch_card',
            'code'            => $code,
            'name'            => $prize->label,
            'description'     => 'Voucher từ thẻ cào sau trả phòng',
            'type'            => $prize->coupon_type ?: 'fixed',
            'value'           => $prize->coupon_value,
            'apply_type'      => 'all_rooms',
            'min_order_value' => $prize->min_order_value,
            'max_discount'    => $prize->max_discount,
            'usage_limit'     => 1,
            'used_count'      => 0,
            'start_at'        => now(),
            'end_at'          => now()->addDays($prize->validity_days)->endOfDay(),
            'is_active'       => true,
            'is_exclusive'    => true,
            'customer_id'     => $customer->id,
            'created_by'      => User::role(config('filament-shield.super_admin.name'))->value('id'),
        ]);
    }
}
