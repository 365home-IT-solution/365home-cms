<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\ReferralSetting;
use App\Models\ScratchCard;
use App\Models\User;
use App\Support\CompletedOrders;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Promotion\App\Models\Coupon;

/**
 * Mini game GIỚI THIỆU BẠN BÈ: mã cố định / khách → bạn mới nhập mã (lúc đăng ký hoặc trong apply_days ngày) nhận voucher chào mừng cho đơn đầu →
 * bạn ở xong đơn đầu (trả phòng + đã trả đủ) thì người giới thiệu nhận 1 thẻ cào (source = referral, xem ScratchCardService::issueReferralCard).
 * Chống gian lận phía BE: không tự nhập mã mình, mỗi tài khoản nhập một lần, SĐT chưa từng có đơn (kể cả đơn vãng lai), một thiết bị chỉ nhập
 * một mã của cùng người giới thiệu, giới hạn thưởng / tháng, đơn đầu bị huỷ/hoàn tiền thì không thưởng.
 */
class ReferralService
{
    // Bỏ 0/O và 1/I cho dễ đọc
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function codeFor(Customer $customer): ReferralCode
    {
        $existing = ReferralCode::where('customer_id', $customer->id)->first();
        if ($existing) {
            return $existing;
        }

        for ($i = 0; $i < 10; $i++) {
            $code = '';
            for ($j = 0; $j < 7; $j++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            try {
                return ReferralCode::create(['customer_id' => $customer->id, 'code' => $code]);
            } catch (UniqueConstraintViolationException) {
                // trùng mã hoặc request khác vừa tạo mã cho khách này
                if ($again = ReferralCode::where('customer_id', $customer->id)->first()) {
                    return $again;
                }
            }
        }

        throw new \RuntimeException('Không tạo được mã giới thiệu.');
    }

    /**
     * Bạn mới nhập mã: kiểm tra chống gian lận rồi tặng voucher chào mừng.
     *
     * @return array{referral: Referral, coupon: Coupon}
     */
    public function apply(Customer $customer, string $code, ?string $deviceId): array
    {
        $setting = ReferralSetting::current();
        $code    = strtoupper(trim($code));

        if (! $setting->is_enabled) {
            $this->fail('Chương trình giới thiệu hiện không hoạt động');
        }

        $referrerCode = ReferralCode::where('code', $code)->first();
        $referrer     = $referrerCode ? Customer::find($referrerCode->customer_id) : null;
        if (! $referrer) {
            $this->fail('Mã giới thiệu không tồn tại');
        }

        if ((string) $referrer->id === (string) $customer->id) {
            $this->fail('Bạn không thể nhập mã giới thiệu của chính mình');
        }

        if (Referral::where('referee_id', $customer->id)->exists()) {
            $this->fail('Bạn đã nhập mã giới thiệu rồi');
        }

        // Hai tài khoản nhập mã của nhau để cùng nhận thưởng
        if (Referral::where('referrer_id', $customer->id)->where('referee_id', $referrer->id)->exists()) {
            $this->fail('Người này đã dùng mã của bạn, không thể nhập mã của họ');
        }

        if ($customer->created_at->copy()->addDays($setting->apply_days)->isPast()) {
            $this->fail("Đã quá {$setting->apply_days} ngày kể từ khi đăng ký, không thể nhập mã giới thiệu");
        }

        if ($this->hasBooked($customer)) {
            $this->fail('Số điện thoại này đã từng đặt phòng nên không đủ điều kiện nhận ưu đãi');
        }

        if ($deviceId && Referral::where('device_id', $deviceId)->where('referrer_id', $referrer->id)->exists()) {
            $this->fail('Thiết bị này đã được dùng để nhập mã giới thiệu này');
        }

        try {
            return DB::transaction(function () use ($customer, $referrer, $code, $deviceId, $setting) {
                $coupon   = $this->createWelcomeCoupon($customer, $setting);
                $referral = Referral::create([
                    'referrer_id' => $referrer->id, 'referee_id' => $customer->id, 'code' => $code, 'device_id' => $deviceId,
                    'status' => Referral::STATUS_JOINED, 'welcome_coupon_id' => $coupon->id,
                ]);

                return ['referral' => $referral, 'coupon' => $coupon];
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('Bạn đã nhập mã giới thiệu rồi');
        }
    }

    // Đơn của bạn được mời vừa trả phòng + trả đủ ⇒ thưởng người giới thiệu (nếu còn hạn mức tháng). Idempotent theo (đơn đầu, source = referral).
    public function onOrderCompleted(Order $order): void
    {
        // Đơn đầu của bạn phải hoàn tất VÀ đạt điều kiện giá trị như thẻ cào (chống tạo tài khoản + đặt đơn rẻ để lấy thưởng)
        if (! $order->customer_id || ! CompletedOrders::isCompleted($order) || ! app(ScratchCardService::class)->meetsMinimum($order)) {
            return;
        }

        $referral = Referral::where('referee_id', $order->customer_id)->where('status', Referral::STATUS_JOINED)->first();
        $referrer = $referral ? Customer::find($referral->referrer_id) : null;
        if (! $referral || ! $referrer || ! ReferralSetting::current()->is_enabled) {
            return;
        }

        $setting = ReferralSetting::current();
        $rewardedThisMonth = Referral::where('referrer_id', $referrer->id)->where('status', Referral::STATUS_REWARDED)
            ->where('rewarded_at', '>=', now()->startOfMonth())->count();

        if ($rewardedThisMonth >= $setting->monthly_limit) {
            $referral->update(['status' => Referral::STATUS_INVALID, 'reason' => 'monthly_limit', 'first_order_id' => $order->id]);

            return;
        }

        $card = app(ScratchCardService::class)->issueReferralCard($referrer, $order)
            ?? ScratchCard::where('order_id', $order->id)->where('source', 'referral')->where('customer_id', $referrer->id)->first(); // đã sinh thẻ nhưng chưa kịp đánh dấu cặp
        if (! $card) {
            return; // thẻ cào đang tắt hoặc đã thưởng — job quét bù sẽ thử lại khi bật
        }

        $referral->update([
            'status' => Referral::STATUS_REWARDED, 'first_order_id' => $order->id, 'scratch_card_id' => $card->id, 'rewarded_at' => now(),
        ]);

        try {
            app(NotificationFcmService::class)->sendToCustomer(
                $referrer, 'Bạn bè của bạn đã ở xong đơn đầu', 'Bạn nhận được 1 thẻ cào thưởng giới thiệu. Cào ngay!', 'referral_reward', [], '/games/referral',
            );
        } catch (\Throwable $e) {
            Log::warning('Referral: gửi thông báo thất bại', ['referral_id' => $referral->id, 'error' => $e->getMessage()]);
        }
    }

    // Đơn đầu của bạn được mời bị huỷ/hoàn tiền ⇒ không thưởng (thẻ đã sinh do ScratchCardService::voidForOrder thu hồi).
    public function voidForOrder(Order $order): void
    {
        Referral::where('first_order_id', $order->id)->where('status', '!=', Referral::STATUS_INVALID)
            ->update(['status' => Referral::STATUS_INVALID, 'reason' => 'order_refunded']);
    }

    // Quét bù đơn trả phòng gần đây của bạn được mời mà hook bỏ sót (vd. thẻ cào đang tắt lúc đó).
    public function sync(int $lookbackDays = 3): void
    {
        $joined = Referral::where('status', Referral::STATUS_JOINED)->pluck('referee_id');
        if ($joined->isEmpty()) {
            return;
        }

        CompletedOrders::recent($lookbackDays)->whereIn('customer_id', $joined)->each(fn (Order $o) => $this->onOrderCompleted($o));
    }

    // Admin huỷ cặp nghi gian lận (một chiều): thu hồi voucher chào mừng + thẻ thưởng nếu chưa dùng.
    public function invalidate(Referral $referral, ?string $reason = null): Referral
    {
        if ($referral->status === Referral::STATUS_INVALID) {
            return $referral;
        }

        $this->revokeIfUnused($referral->welcomeCoupon);

        if ($referral->scratch_card_id && ($card = ScratchCard::with('coupon')->find($referral->scratch_card_id))) {
            if (! $card->coupon || (int) $card->coupon->used_count === 0) {
                $card->coupon?->update(['is_active' => false]);
                if ($card->status !== ScratchCard::STATUS_VOIDED) {
                    $card->update(['status' => ScratchCard::STATUS_VOIDED]);
                }
            }
        }

        $referral->update(['status' => Referral::STATUS_INVALID, 'reason' => $reason ?: 'admin']);

        return $referral->fresh();
    }

    // Dữ liệu màn "Mã của tôi".
    public function mePayload(Customer $customer): array
    {
        $setting = ReferralSetting::current();
        $code    = $this->codeFor($customer)->code;
        $url     = rtrim($setting->share_url_base, '/') . '/' . $code;
        $label   = $this->welcomeLabel($setting);

        $applied = Referral::where('referee_id', $customer->id)->first();

        $invited = Referral::where('referrer_id', $customer->id)->with('referee')->orderByDesc('created_at')->get()->map(function (Referral $r) {
            $status = $r->status === Referral::STATUS_JOINED && $r->referee && $this->hasBooked($r->referee) ? 'booked' : $r->status;

            return [
                'name'      => self::maskName((string) $r->referee?->fullname),
                'phone'     => self::maskPhone((string) $r->referee?->phone),
                'status'    => $status,
                'reason'    => $r->status === Referral::STATUS_INVALID ? $r->reason : null,
                'joined_at' => $r->created_at?->toDateTimeString(),
            ];
        })->values();

        $deadline = $customer->created_at->copy()->addDays($setting->apply_days);
        $canApply = $setting->is_enabled && ! $applied && $deadline->isFuture() && ! $this->hasBooked($customer);

        return [
            'code'                  => $code,
            'share_url'             => $url,
            'share_text'            => "Đặt phòng 365home bằng mã {$code} để được " . Str::lower($label) . ": {$url}",
            'referee_reward_label'  => $label,
            'referrer_reward_label' => '1 thẻ cào khi bạn ở xong đơn đầu',
            'monthly_limit'         => $setting->monthly_limit,
            'rewarded_this_month'   => Referral::where('referrer_id', $customer->id)->where('status', Referral::STATUS_REWARDED)->where('rewarded_at', '>=', now()->startOfMonth())->count(),
            'invited'               => $invited,
            'applied_code'          => $applied?->code,
            'can_apply_until'       => $canApply ? $deadline->toDateTimeString() : null,
        ];
    }

    public function welcomeLabel(ReferralSetting $setting): string
    {
        $value = $setting->coupon_type === 'percentage'
            ? rtrim(rtrim((string) $setting->coupon_value, '0'), '.') . '%'
            : number_format((float) $setting->coupon_value, 0, ',', '.') . 'đ';

        return "Giảm {$value} cho đơn đầu";
    }

    public static function maskName(string $name): string
    {
        return collect(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $w, int $i) => mb_substr($w, 0, $i === 0 ? 2 : 1) . '***')->implode(' ');
    }

    public static function maskPhone(string $phone): string
    {
        $national = str_starts_with($phone, '84') ? '0' . substr($phone, 2) : $phone;

        return strlen($national) >= 6 ? substr($national, 0, 2) . '****' . substr($national, -3) : $national;
    }

    // SĐT của khách đã từng có đơn (mọi dạng 0xxx / 84xxx / +84xxx, kể cả đơn vãng lai) hoặc tài khoản đã có đơn.
    private function hasBooked(Customer $customer): bool
    {
        $digits   = preg_replace('/\D/', '', (string) $customer->phone);
        $national = str_starts_with($digits, '84') ? substr($digits, 2) : ltrim($digits, '0');
        $variants = array_unique([$customer->phone, $digits, '0' . $national, '+84' . $national, '84' . $national]);

        return Order::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhereIn('buyer_phone', $variants))
            ->whereNotIn('status', ['failed', 'cancelled_payment'])
            ->exists();
    }

    private function revokeIfUnused(?Coupon $coupon): void
    {
        if ($coupon && (int) $coupon->used_count === 0) {
            $coupon->update(['is_active' => false]);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['message' => [$message]])->status(422);
    }

    private function createWelcomeCoupon(Customer $customer, ReferralSetting $setting): Coupon
    {
        do {
            $code = 'RF' . strtoupper(Str::random(6));
        } while (Coupon::withoutGlobalScopes()->where('code', $code)->exists());

        return Coupon::create([
            'partner_id'      => null,
            'funded_by'       => Coupon::FUNDED_PLATFORM,
            'source'          => 'referral',
            'code'            => $code,
            'name'            => $this->welcomeLabel($setting),
            'description'     => 'Quà chào mừng từ chương trình giới thiệu bạn bè',
            'type'            => $setting->coupon_type,
            'value'           => $setting->coupon_value,
            'apply_type'      => 'all_rooms',
            'min_order_value' => $setting->min_order_value,
            'max_discount'    => $setting->max_discount,
            'usage_limit'     => 1,
            'used_count'      => 0,
            'start_at'        => now(),
            'end_at'          => now()->addDays($setting->validity_days)->endOfDay(),
            'is_active'       => true,
            'is_exclusive'    => true,
            'customer_id'     => $customer->id,
            'created_by'      => User::role(config('filament-shield.super_admin.name'))->value('id'),
        ]);
    }
}
