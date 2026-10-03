<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\SubscriptionPayment as Pay;
use App\Models\SubscriptionPlan;
use App\Services\PartnerOnboardingService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// MiniHouse: MUA GÓI RỒI DÙNG — không đăng ký đối tác, không ký hợp đồng.
// Khách chọn gói + số tháng, nhập thông tin liên hệ → hệ thống tạo hồ sơ chờ thanh toán + giao dịch PayOS (QR/link).
// Thanh toán xong (webhook PayOS hoặc Super Admin xác nhận) → tự kích hoạt gói, tạo tài khoản quản lý MiniHouse và gửi email đăng nhập.
class MinihousePurchaseController extends Controller
{
    public function __construct(
        private readonly PartnerOnboardingService $onboarding,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    // GET /api/public/minihouse-plans — gói đang bán + các kỳ mua và số tiền
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::query()->where('is_active', true)->forPartnerType(Partner::TYPE_MINIHOUSE)->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'data' => $plans->map(fn (SubscriptionPlan $p) => $p->toApi())->values(),
            'meta' => ['signup_trial_months' => (int) config('partner_flow.minihouse_signup_trial_months', 0)],
        ]);
    }

    // POST /api/public/minihouse-purchase
    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id'               => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'periods'               => ['nullable', 'integer', Rule::in(config('subscription.period_options', [1, 3, 6, 9, 12]))],
            'full_name'             => ['required', 'string', 'max:255'],
            'phone'                 => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
            'email'                 => ['required', 'email', 'max:255'],
            'business_name'         => ['required', 'string', 'max:255'],
            'address'               => ['required_without:address_street', 'nullable', 'string', 'max:500'],
            'address_street'        => ['required_without:address', 'nullable', 'string', 'max:255'],
            'address_province_code' => ['required_with:address_street', 'nullable', 'integer', Rule::exists(\App\Models\Province::class, 'code')],
            'address_ward_code'     => ['required_with:address_street', 'nullable', 'integer', Rule::exists(\App\Models\Ward::class, 'code')],
            'address_unit'          => ['nullable', 'string', 'max:100'],
            'address_building'      => ['nullable', 'string', 'max:150'],
            'postal_code'           => ['nullable', 'regex:/^[0-9]{5,6}$/'],
        ], [
            'phone.regex'                 => 'Số điện thoại không hợp lệ.',
            'postal_code.regex'           => 'Mã bưu điện gồm 5–6 chữ số.',
            'address_street.required_without' => 'Vui lòng nhập số nhà, tên đường/phố.',
            'address.required_without'    => 'Vui lòng nhập địa chỉ.',
        ], PartnerOnboardingService::LABELS + ['plan_id' => 'gói dịch vụ', 'periods' => 'số tháng']);

        // Đăng ký lần đầu (được tặng dùng thử) không cần chọn gói: dùng gói MiniHouse đang bán; số kỳ mặc định 1 (chỉ dùng khi phải thanh toán trước).
        $plan = filled($data['plan_id'] ?? null)
            ? SubscriptionPlan::query()->findOrFail($data['plan_id'])
            : SubscriptionPlan::query()->where('is_active', true)->forPartnerType(Partner::TYPE_MINIHOUSE)->orderBy('sort_order')->orderBy('id')->first();
        abort_if(! $plan, 422, 'Chưa có gói MiniHouse đang bán. Vui lòng liên hệ 365 Home.');
        $data['periods'] = (int) ($data['periods'] ?? 1);
        abort_unless($plan->is_active && ($plan->partner_type === null || $plan->partner_type === Partner::TYPE_MINIHOUSE), 422, 'Gói này không áp dụng cho MiniHouse.');

        $data['address'] = $this->onboarding->composeAddress($data);
        $result = $this->onboarding->createPurchasePartner($data);

        // Lần đầu (đủ điều kiện tặng dùng thử): chỉ XÁC NHẬN ĐĂNG KÝ — ghi nhận gói khách chọn, KHÔNG tạo đơn thanh toán/QR; Super Admin duyệt xong mới tặng dùng thử.
        // Không đủ điều kiện (đã từng đăng ký / tính năng tắt): tạo đơn thanh toán + QR, phải thanh toán mới được dùng.
        $pendingApproval = $this->onboarding->awaitingSignupApproval($result['partner']);
        if ($pendingApproval) {
            $result['partner']->update(['signup_plan_id' => $plan->id, 'signup_periods' => (int) $data['periods']]);
            $payment = null;
        } else {
            $payment = $this->subscriptions->createCheckout($result['partner'], $plan, (int) $data['periods']);
        }
        $result['partner'] = $result['partner']->fresh();

        return response()->json([
            'message' => $pendingApproval
                ? 'Đã xác nhận đăng ký MiniHouse. Sau khi 365 Home duyệt, tài khoản dùng thử và mật khẩu sẽ được gửi về email của bạn.'
                : 'Đã tạo đơn mua gói. Quét QR hoặc mở link để thanh toán; sau khi thanh toán, tài khoản đăng nhập sẽ được gửi về email của bạn.',
            'data'    => ['purchase_token' => $result['token'], ...$this->status($result['partner'], $payment)],
        ], 201);
    }

    // GET /api/public/minihouse-purchase/{token} — trạng thái đơn mua (app/web gọi lại để biết đã thanh toán chưa)
    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->status($this->onboarding->findByToken($token))]);
    }

    private function status(Partner $partner, ?Pay $payment = null): array
    {
        $payment ??= Pay::query()->with('plan')->where('partner_id', $partner->id)->latest('id')->first();
        $paid = $payment?->status === Pay::STATUS_PAID;
        $hasAccount = $partner->users()->exists();

        return [
            'stage'        => match (true) {
                $paid && $hasAccount => 'active',
                $paid                => 'paid',
                $hasAccount && $partner->subscription?->state() === \App\Models\PartnerSubscription::STATE_TRIAL => 'trial',
                $this->onboarding->awaitingSignupApproval($partner) && ! in_array($payment?->status, [Pay::STATUS_CANCELLED, Pay::STATUS_EXPIRED], true) => 'pending_approval',
                $payment?->status === Pay::STATUS_CANCELLED => 'cancelled',
                $payment?->status === Pay::STATUS_EXPIRED   => 'expired',
                default              => 'pending_payment',
            },
            'partner'      => $partner->only(['name', 'phone', 'email', 'address']),
            'payment'      => $payment?->loadMissing('plan')->toApi(),
            'subscription' => $partner->subscription ? [
                'expires_at' => $partner->subscription->expires_at?->toIso8601String(),
                'status'     => $partner->subscription->state(),
                'is_trial'   => (bool) $partner->subscription->is_trial,
                'days_left'  => $partner->subscription->daysLeft(),
            ] : null,
            'account'      => [
                'created'   => $hasAccount,
                'email'     => $hasAccount ? $partner->email : null,
                'login_url' => $hasAccount ? $this->onboarding->loginUrl($partner) : null,
            ],
        ];
    }
}
