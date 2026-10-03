<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerSubscription as Sub;
use App\Models\SubscriptionPayment as Pay;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\SubscriptionGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Minihouse\App\Support\HomestayBridge;

// Vòng đời GÓI DỊCH VỤ của đối tác: tặng gói dùng thử khi đăng ký, đổi gói, thanh toán phí (PayOS) → tự gia hạn/kích hoạt,
// nhắc trước khi hết hạn, link thanh toán tự động khi bật tự gia hạn.
class SubscriptionService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private SubscriptionPayosService $payos) {}

    /** Gói dùng thử mặc định cho đối tác mới (theo loại đối tác); không có gói mặc định thì bỏ qua. */
    public function startTrial(Partner $partner): ?Sub
    {
        if ($partner->id === HomestayBridge::PARTNER_ID || $partner->subscription()->exists() || $partner->partner_type !== Partner::TYPE_MINIHOUSE) {
            return null;
        }

        // MiniHouse mua gói trên website (hồ sơ có mã đơn) không được tặng dùng thử — gói bắt đầu khi thanh toán xong.
        if (filled($partner->onboarding_token) && ! $partner->usesContract()) {
            return null;
        }

        $plan = SubscriptionPlan::query()->where('is_active', true)->where('is_default', true)->forPartnerType($partner->partner_type)
            ->orderByRaw('partner_type is null')->first();

        if (! $plan) {
            return null;
        }

        $months = $plan->trial_months > 0 ? $plan->trial_months : (int) config('subscription.default_trial_months', 3);

        return Sub::create([
            'partner_id' => $partner->id,
            'plan_id'    => $plan->id,
            'status'     => Sub::STATUS_TRIAL,
            'is_trial'   => true,
            'started_at' => now(),
            'expires_at' => now()->addMonthsNoOverflow($months),
        ]);
    }

    /**
     * Gán gói DÙNG THỬ do người tạo đối tác CHỌN (thay cho gói mặc định tự gán lúc tạo). Số tháng dùng thử lấy từ gói đó
     * (trial_months; 0 → mặc định cấu hình). Gói phải đang bán và đúng loại đối tác.
     */
    public function startTrialWithPlan(Partner $partner, SubscriptionPlan $plan): ?Sub
    {
        if ($partner->partner_type !== Partner::TYPE_MINIHOUSE) {
            return null; // Chỉ MiniHouse dùng gói
        }

        if (! $plan->is_active || $plan->partner_type !== $partner->partner_type) {
            throw ValidationException::withMessages(['plan_id' => 'Gói không áp dụng cho loại đối tác này.']);
        }

        $months = $plan->trial_months > 0 ? $plan->trial_months : (int) config('subscription.default_trial_months', 3);

        return $this->assignPlan($partner, $plan, now()->addMonthsNoOverflow($months), true);
    }

    /** Super Admin gán/đổi gói. $expiresAt null = không giới hạn. */
    public function assignPlan(Partner $partner, SubscriptionPlan $plan, ?\DateTimeInterface $expiresAt, bool $trial = false): Sub
    {
        $sub = Sub::updateOrCreate(['partner_id' => $partner->id], [
            'plan_id'              => $plan->id,
            'status'               => Sub::STATUS_ACTIVE,
            'is_trial'             => $trial,
            'started_at'           => now(),
            'expires_at'           => $expiresAt,
            'reminders_sent'       => [],
            'renewal_link_sent_at' => null,
            'expired_notified_at'  => null,
        ]);

        SubscriptionGate::flush();

        return $sub;
    }

    /** Cộng thêm $months vào hạn dùng (từ ngày hết hạn hiện tại nếu còn hạn, ngược lại từ hôm nay). */
    public function extend(Sub $sub, int $months): Sub
    {
        $base = $sub->expires_at && $sub->expires_at->isFuture() ? $sub->expires_at->copy() : now();

        $sub->update([
            'status'               => Sub::STATUS_ACTIVE,
            'is_trial'             => false,
            'expires_at'           => $base->addMonthsNoOverflow($months),
            'reminders_sent'       => [],
            'renewal_link_sent_at' => null,
            'expired_notified_at'  => null,
        ]);

        SubscriptionGate::flush();

        return $sub->fresh();
    }

    /** Tạo yêu cầu thanh toán phí + link/QR PayOS. Hủy các yêu cầu đang chờ cũ của đối tác. */
    public function createCheckout(Partner $partner, SubscriptionPlan $plan, int $periods = 1, bool $renewal = false, string $source = 'payos'): Pay
    {
        if (! $plan->is_active || ($plan->partner_type && $plan->partner_type !== $partner->partner_type)) {
            throw ValidationException::withMessages(['plan_id' => 'Gói này không áp dụng cho tài khoản của bạn.']);
        }

        if (! in_array($periods, config('subscription.period_options'), true)) {
            throw ValidationException::withMessages(['periods' => 'Số tháng thanh toán phải là ' . implode(', ', config('subscription.period_options')) . '.']);
        }

        // Số tiền = giá/tháng × số kỳ (1/3/6/9/12), trừ % ưu đãi của kỳ đó (nếu có).
        $amount = $plan->amountFor($periods);

        // Chống spam: bấm chọn gói nhiều lần KHÔNG sinh thêm giao dịch — dùng lại giao dịch đang chờ cùng gói + số kỳ (còn hiệu lực).
        $months = $plan->period_months * $periods;
        $existing = Pay::query()->with('plan')->where('partner_id', $partner->id)->where('status', Pay::STATUS_PENDING)
            ->where('plan_id', $plan->id)->where('months', $months)->where('amount_vnd', $plan->price_vnd > 0 ? $amount : 0)
            ->where(fn ($q) => $q->whereNull('payos_expired_at')->orWhere('payos_expired_at', '>', now()))->latest('id')->first();

        if ($existing) {
            return $existing;
        }

        // Giới hạn tốc độ tạo giao dịch mới mỗi đối tác.
        if (Pay::query()->where('partner_id', $partner->id)->where('created_at', '>=', now()->subHour())->count() >= (int) config('subscription.max_payments_per_hour', 6)) {
            throw ValidationException::withMessages(['plan_id' => 'Bạn thao tác quá nhiều lần, vui lòng thử lại sau ít phút.']);
        }

        // Gói chưa cấu hình phí: không có số tiền để tạo QR → ghi nhận YÊU CẦU đăng ký/gia hạn (có mã giao dịch), báo Super Admin liên hệ,
        // nhận tiền rồi xác nhận (Filament → Thanh toán gói → "Xác nhận đã nhận tiền", nhập số tiền thực nhận).
        if ($plan->price_vnd < 1) {
            return $this->createRequest($partner, $plan, $periods, $renewal);
        }

        if ($amount < 2000) {
            throw ValidationException::withMessages(['plan_id' => 'Số tiền thanh toán tối thiểu là 2.000đ.']);
        }

        Pay::query()->where('partner_id', $partner->id)->where('status', Pay::STATUS_PENDING)->get()->each(function (Pay $old) {
            $this->payos->cancelLink($old);
            $old->update(['status' => Pay::STATUS_CANCELLED]);
        });

        $payment = Pay::create([
            'partner_id'       => $partner->id,
            'plan_id'          => $plan->id,
            'transaction_code' => $this->newCode(),
            'amount_vnd'       => $amount,
            'months'           => $plan->period_months * $periods,
            'status'           => Pay::STATUS_PENDING,
            'source'           => $source,
            'is_renewal'       => $renewal,
        ]);

        $this->payos->createLink($payment);

        return $payment->fresh();
    }

    /** Yêu cầu đăng ký/gia hạn cho gói chưa có phí (không PayOS). Số tiền = 0 cho tới khi Super Admin xác nhận. */
    private function createRequest(Partner $partner, SubscriptionPlan $plan, int $periods, bool $renewal): Pay
    {
        Pay::query()->where('partner_id', $partner->id)->where('status', Pay::STATUS_PENDING)->get()->each(function (Pay $old) {
            $this->payos->cancelLink($old);
            $old->update(['status' => Pay::STATUS_CANCELLED]);
        });

        $payment = Pay::create([
            'partner_id'       => $partner->id,
            'plan_id'          => $plan->id,
            'transaction_code' => $this->newCode(),
            'amount_vnd'       => 0,
            'months'           => $plan->period_months * $periods,
            'status'           => Pay::STATUS_PENDING,
            'source'           => 'request',
            'is_renewal'       => $renewal,
            'note'             => 'Yêu cầu đăng ký/gia hạn — gói chưa cấu hình phí, chờ công ty báo số tiền và xác nhận.',
        ]);

        // Chỉ báo Super Admin 1 lần / 6 giờ cho mỗi đối tác (tránh spam khi bấm nhiều lần).
        $recentRequests = Pay::query()->where('partner_id', $partner->id)->where('source', 'request')->where('id', '!=', $payment->id)->where('created_at', '>=', now()->subHours(6))->exists();

        if (! $recentRequests) {
            $this->notifySuperAdmins('Đối tác yêu cầu đăng ký/gia hạn gói', $this->partnerName($partner) . ' yêu cầu gói ' . $plan->name . ' (' . $payment->months . ' tháng), mã ' . $payment->transaction_code . ' — cần liên hệ báo số tiền và xác nhận khi nhận được tiền.', 'subscription_request');
        }

        return $payment->fresh();
    }

    /** Hủy yêu cầu thanh toán đang chờ của chính đối tác. */
    public function cancelPayment(Pay $payment): Pay
    {
        if ($payment->status !== Pay::STATUS_PENDING) {
            throw ValidationException::withMessages(['payment' => 'Chỉ huỷ được giao dịch đang chờ thanh toán.']);
        }

        $this->payos->cancelLink($payment);
        $payment->update(['status' => Pay::STATUS_CANCELLED]);

        return $payment;
    }

    /**
     * Ghi nhận thanh toán thành công (từ webhook PayOS hoặc Super Admin) → tự đổi gói theo giao dịch & gia hạn/kích hoạt.
     * Idempotent theo mã giao dịch ngân hàng và trạng thái. Trả về null nếu bỏ qua.
     */
    public function markPaid(Pay $payment, int $paidVnd, string $reference): ?Pay
    {
        $done = DB::transaction(function () use ($payment, $paidVnd, $reference) {
            $payment = Pay::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status === Pay::STATUS_PAID || Pay::query()->where('bank_reference', $reference)->where('id', '!=', $payment->id)->exists()) {
                return null;
            }

            if ($paidVnd < $payment->amount_vnd) {
                $payment->update(['note' => trim(($payment->note ? $payment->note . ' | ' : '') . 'Nhận ' . number_format($paidVnd) . 'đ, thiếu so với ' . number_format($payment->amount_vnd) . 'đ (mã GD ' . $reference . ') — cần xử lý thủ công.')]);
                $this->notifySuperAdmins('Thanh toán gói dịch vụ chưa đủ tiền', $this->partnerName($payment->partner) . ' trả ' . number_format($paidVnd) . 'đ / ' . number_format($payment->amount_vnd) . 'đ cho giao dịch ' . $payment->transaction_code . ' — chưa kích hoạt, cần xử lý.', 'subscription_underpaid');

                return null;
            }

            $partner = Partner::withTrashed()->findOrFail($payment->partner_id);
            $sub = Sub::query()->where('partner_id', $partner->id)->lockForUpdate()->first();

            if (! $sub) {
                $sub = new Sub(['partner_id' => $partner->id, 'plan_id' => $payment->plan_id]);
            }

            // Đổi sang gói đã thanh toán (nâng/hạ gói theo giao dịch).
            $sub->plan_id = $payment->plan_id;
            $sub->save();

            $sub = $this->extend($sub, $payment->months);

            $payment->update([
                // Yêu cầu của gói chưa có phí: ghi nhận số tiền thực nhận.
                'amount_vnd'     => $payment->amount_vnd > 0 ? $payment->amount_vnd : $paidVnd,
                'status'         => Pay::STATUS_PAID,
                'bank_reference' => $reference,
                'paid_at'        => now(),
                'extends_to'     => $sub->expires_at,
            ]);

            return $payment->fresh(['partner', 'plan']);
        });

        if ($done) {
            // MiniHouse mua gói lần đầu trên website: thanh toán xong → kích hoạt + tạo tài khoản + gửi email đăng nhập.
            if ($done->partner && $done->partner->isMinihouse() && filled($done->partner->onboarding_token) && ! $done->partner->users()->exists()) {
                try {
                    app(PartnerOnboardingService::class)->provisionPurchasedAccount($done->partner);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $this->notifyPartner($done->partner, 'Thanh toán gói dịch vụ thành công', 'Gói ' . $done->plan?->name . ' đã được gia hạn đến ' . $done->extends_to?->format('d/m/Y') . ' (mã GD ' . $done->transaction_code . ').', 'subscription_paid', 'success');
            $this->notifySuperAdmins('Đối tác đã thanh toán gói dịch vụ', $this->partnerName($done->partner) . ' thanh toán ' . number_format($done->amount_vnd) . 'đ — gói ' . $done->plan?->name . ', mã GD ' . $done->transaction_code . ', đã tự gia hạn.', 'subscription_paid');
        }

        return $done;
    }

    /** Lệnh định kỳ: hết hạn, nhắc trước hạn, link tự gia hạn, dọn yêu cầu thanh toán quá hạn. @return array<string,int> */
    public function processDaily(): array
    {
        $stats = ['expired_notified' => 0, 'reminders' => 0, 'renewal_links' => 0, 'payments_expired' => 0, 'payments_pruned' => 0];

        Pay::query()->where('status', Pay::STATUS_PENDING)->whereNotNull('payos_expired_at')->where('payos_expired_at', '<', now())->get()->each(function (Pay $p) use (&$stats) {
            $p->update(['status' => Pay::STATUS_EXPIRED]);
            $stats['payments_expired']++;
        });

        // Yêu cầu (gói chưa có phí) quá 7 ngày chưa xử lý → đóng; giao dịch huỷ/hết hạn quá 30 ngày → xoá (không có tiền, chỉ là ý định mua).
        Pay::query()->where('status', Pay::STATUS_PENDING)->where('source', 'request')->where('created_at', '<', now()->subDays(7))->update(['status' => Pay::STATUS_EXPIRED]);
        $stats['payments_pruned'] = Pay::query()->whereIn('status', [Pay::STATUS_CANCELLED, Pay::STATUS_EXPIRED])->where('updated_at', '<', now()->subDays(30))->delete();

        Sub::query()->with(['partner', 'plan'])->where('status', '!=', Sub::STATUS_CANCELLED)->whereNotNull('expires_at')->get()->each(function (Sub $sub) use (&$stats) {
            $partner = $sub->partner;
            if (! $partner || $partner->partner_type !== Partner::TYPE_MINIHOUSE) {
                return;
            }

            if ($sub->isExpired()) {
                if (! $sub->expired_notified_at) {
                    $sub->update(['expired_notified_at' => now()]);
                    $this->notifyPartner($partner, 'Gói dịch vụ đã hết hạn', 'Tài khoản đã bị khoá các tính năng. Vui lòng thanh toán phí để tiếp tục sử dụng.', 'subscription_expired', 'danger');
                    $this->notifySuperAdmins('Đối tác hết hạn gói dịch vụ', $this->partnerName($partner) . ' đã hết hạn gói ' . $sub->plan?->name . '.', 'subscription_expired');
                    $stats['expired_notified']++;
                }

                return;
            }

            $days = (int) ceil(now()->diffInSeconds($sub->expires_at, false) / 86400);
            $sent = (array) $sub->reminders_sent;
            $marks = collect(config('subscription.reminder_days'))->sort()->values();
            $due = $marks->first(fn ($m) => $days <= $m && ! in_array($m, $sent, true));

            if ($due !== null) {
                $sub->update(['reminders_sent' => array_values(array_unique(array_merge($sent, $marks->filter(fn ($m) => $m >= $days)->all())))]);
                $this->notifyPartner($partner, 'Gói dịch vụ sắp hết hạn', 'Gói ' . $sub->plan?->name . ' còn ' . max($days, 0) . ' ngày (hết hạn ' . $sub->expires_at->format('d/m/Y') . '). Vui lòng gia hạn để không bị gián đoạn.', 'subscription_expiring', 'warning');
                $stats['reminders']++;
            }

            if ($sub->auto_renew && ! $sub->renewal_link_sent_at && $days <= (int) config('subscription.auto_renew_days_before', 7) && $sub->plan && $sub->plan->price_vnd > 0) {
                try {
                    $payment = $this->createCheckout($partner, $sub->plan, 1, true, 'auto_renew');
                    $sub->update(['renewal_link_sent_at' => now()]);
                    $this->notifyPartner($partner, 'Link gia hạn gói dịch vụ', 'Đã tạo sẵn thanh toán gia hạn ' . number_format($payment->amount_vnd) . 'đ (mã GD ' . $payment->transaction_code . '). Mở mục Gói dịch vụ để thanh toán.', 'subscription_renewal_link', 'info');
                    $stats['renewal_links']++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        return $stats;
    }

    private function newCode(): string
    {
        do {
            $code = 'SB';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Pay::query()->where('transaction_code', $code)->exists());

        return $code;
    }

    private function partnerName(?Partner $partner): string
    {
        return $partner?->legal_name ?: ($partner?->name ?? 'Đối tác');
    }

    private function notifyPartner(?Partner $partner, string $title, string $body, string $type, string $color): void
    {
        if (! $partner) {
            return;
        }

        try {
            app(AdminNotificationService::class)->notify(
                User::query()->where('partner_id', $partner->id)->get(),
                $title,
                $body,
                ['type' => $type, 'partner_id' => $partner->id],
                'heroicon-o-credit-card',
                $color,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifySuperAdmins(string $title, string $body, string $type): void
    {
        try {
            app(AdminNotificationService::class)->notify(
                User::role(config('filament-shield.super_admin.name'))->get(),
                $title,
                $body,
                ['type' => $type],
                'heroicon-o-credit-card',
                'info',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
