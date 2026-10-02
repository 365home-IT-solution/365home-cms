<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerSubscription;
use App\Models\SubscriptionPayment as Pay;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\SubscriptionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GÓI DỊCH VỤ của đối tác MiniHouse (Homestay không dùng gói). Luôn dùng được kể cả khi gói hết hạn (để xem & thanh toán).
 *
 * GET  /api/admin/subscription                  → gói hiện tại, trạng thái, hạn, cờ khoá
 * GET  /api/admin/subscription/plans            → gói bán (1 gói) + các kỳ mua 1/3/6/9/12 tháng và số tiền
 * POST /api/admin/subscription/checkout         → tạo thanh toán {plan_id, periods} → mã giao dịch + link/QR PayOS
 * GET  /api/admin/subscription/payments         → lịch sử thanh toán (?status=)
 * GET  /api/admin/subscription/payments/{id}    → chi tiết/trạng thái 1 giao dịch (app gọi lại để biết đã thanh toán chưa)
 * POST /api/admin/subscription/payments/{id}/cancel
 * PUT  /api/admin/subscription/auto-renew       → {auto_renew: bool} bật/tắt tự gia hạn
 */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    /** Mô tả gói của 1 user — dùng chung cho login/me. */
    public static function summary(User $user): array
    {
        $state = SubscriptionGate::stateFor($user);
        $sub = $state['sub'];

        return [
            'managed'       => ! $state['exempt'],
            'status'        => $sub?->state(),
            'status_label'  => $sub ? PartnerSubscription::STATES[$sub->state()] : null,
            'locked'        => $state['locked'],
            'is_trial'      => (bool) $sub?->is_trial,
            'plan'          => $sub?->plan?->only(['id', 'code', 'name']),
            'started_at'    => $sub?->started_at?->toIso8601String(),
            'expires_at'    => $sub?->expires_at?->toIso8601String(),
            'days_left'     => $sub?->daysLeft(),
            'auto_renew'    => (bool) $sub?->auto_renew,
        ];
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $partner = $this->partner($user);

        $pending = $partner ? Pay::query()->with('plan')->where('partner_id', $partner->id)->where('status', Pay::STATUS_PENDING)->latest('id')->first() : null;

        return response()->json(['data' => [
            ...self::summary($user),
            'pending_payment' => $pending?->toApi(),
        ]]);
    }

    public function plans(Request $request): JsonResponse
    {
        $partner = $this->partner($request->user());

        // Homestay không dùng gói (đăng nhập là dùng): không có gói để chọn.
        if ($partner && $partner->partner_type !== Partner::TYPE_MINIHOUSE) {
            return response()->json(['data' => []]);
        }

        $plans = SubscriptionPlan::query()->where('is_active', true)->forPartnerType($partner?->partner_type)->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $plans->map(fn ($p) => $p->toApi())->values()]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $partner = $this->partner($request->user()) ?? abort(403, 'Tài khoản không thuộc đối tác nào.');
        abort_unless($partner->partner_type === Partner::TYPE_MINIHOUSE, 422, 'Tài khoản Homestay không cần đăng ký gói dịch vụ.');
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'periods' => ['nullable', 'integer', \Illuminate\Validation\Rule::in(config('subscription.period_options', [1, 3, 6, 9, 12]))],
        ]);

        $payment = $this->subscriptions->createCheckout($partner, SubscriptionPlan::findOrFail($data['plan_id']), (int) ($data['periods'] ?? 1));

        return response()->json([
            'message' => $payment->source === 'request'
                ? 'Gói này chưa có phí cố định. Đã gửi yêu cầu tới công ty; công ty sẽ liên hệ báo số tiền và xác nhận khi nhận được tiền (nội dung chuyển khoản: ' . $payment->transaction_code . ').'
                : 'Đã tạo yêu cầu thanh toán. Quét QR/mở link để thanh toán; hệ thống tự gia hạn khi nhận được tiền.',
            'data'    => $payment->load('plan')->toApi(),
        ], 201);
    }

    public function payments(Request $request): JsonResponse
    {
        $partner = $this->partner($request->user());
        $request->validate(['status' => ['nullable', 'in:' . implode(',', array_keys(Pay::STATUSES))]]);

        $rows = Pay::query()->with('plan')->where('partner_id', $partner?->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json([
            'data' => collect($rows->items())->map(fn ($p) => $p->toApi())->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }

    public function payment(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->ownPayment($request, $id)->toApi()]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $payment = $this->subscriptions->cancelPayment($this->ownPayment($request, $id));

        return response()->json(['message' => 'Đã huỷ giao dịch.', 'data' => $payment->toApi()]);
    }

    public function autoRenew(Request $request): JsonResponse
    {
        $partner = $this->partner($request->user()) ?? abort(403, 'Tài khoản không thuộc đối tác nào.');
        abort_unless($partner->partner_type === Partner::TYPE_MINIHOUSE, 422, 'Tài khoản Homestay không cần đăng ký gói dịch vụ.');
        $data = $request->validate(['auto_renew' => ['required', 'boolean']]);
        $sub = $partner->subscription ?? abort(404, 'Chưa có gói dịch vụ.');

        $sub->update(['auto_renew' => (bool) $data['auto_renew']]);
        SubscriptionGate::flush();

        return response()->json(['message' => $sub->auto_renew ? 'Đã bật tự động gia hạn: hệ thống tạo sẵn link thanh toán và thông báo trước hạn.' : 'Đã tắt tự động gia hạn.', 'data' => self::summary($request->user())]);
    }

    private function partner(User $user): ?Partner
    {
        return $user->partner_id ? Partner::query()->find($user->partner_id) : null;
    }

    private function ownPayment(Request $request, int $id): Pay
    {
        $partner = $this->partner($request->user());

        return Pay::query()->with('plan')->where('partner_id', $partner?->id)->find($id) ?? abort(404, 'Không tìm thấy giao dịch.');
    }
}
