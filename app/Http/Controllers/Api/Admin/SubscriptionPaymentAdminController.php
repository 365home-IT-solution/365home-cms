<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\SubscriptionPayment as Pay;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Super Admin: xem giao dịch gói dịch vụ MiniHouse và XÁC NHẬN ĐÃ NHẬN TIỀN khi webhook PayOS chưa tự ghi nhận
// (cùng logic nút "Xác nhận đã nhận tiền" ở Filament → Thanh toán gói): gia hạn/kích hoạt gói, tạo tài khoản + gửi email.
class SubscriptionPaymentAdminController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $this->superAdmin($request);
        $request->validate(['status' => ['nullable', 'in:' . implode(',', array_keys(Pay::STATUSES))], 'search' => ['nullable', 'string', 'max:100']]);

        $rows = Pay::query()->with(['plan', 'partner'])
            ->whereHas('partner', fn ($p) => $p->where('partner_type', Partner::TYPE_MINIHOUSE))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->input('search') . '%';
                $q->where(fn ($w) => $w->where('transaction_code', 'like', $term)
                    ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', $term)->orWhere('legal_name', 'like', $term)));
            })
            ->latest('id')->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json([
            'data' => collect($rows->items())->map(fn (Pay $p) => $this->format($p))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $this->superAdmin($request);
        $payment = Pay::query()->with(['plan', 'partner'])
            ->whereHas('partner', fn ($p) => $p->where('partner_type', Partner::TYPE_MINIHOUSE))
            ->findOrFail($id);

        abort_unless($payment->status === Pay::STATUS_PENDING, 422, 'Chỉ xác nhận được giao dịch đang chờ thanh toán.');

        $data = $request->validate([
            'amount'    => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:120'],
        ], [
            'amount.required'    => 'Vui lòng nhập số tiền đã nhận.',
            'amount.integer'     => 'Số tiền đã nhận phải là số nguyên (đồng).',
            'amount.min'         => 'Số tiền đã nhận phải lớn hơn 0.',
            'reference.required' => 'Vui lòng nhập mã giao dịch ngân hàng.',
        ]);

        $done = $this->subscriptions->markPaid($payment, (int) $data['amount'], (string) $data['reference']);
        abort_if($done === null, 422, 'Chưa kích hoạt: số tiền chưa đủ hoặc mã giao dịch ngân hàng đã được dùng cho giao dịch khác.');

        return response()->json(['message' => 'Đã xác nhận và gia hạn gói.', 'data' => $this->format($done->load(['plan', 'partner.subscription']))]);
    }

    private function format(Pay $payment): array
    {
        $sub = $payment->partner?->subscription;

        return $payment->toApi() + [
            'partner'      => $payment->partner?->only(['id', 'name', 'legal_name', 'phone', 'email']),
            'note'         => $payment->note,
            'subscription' => $sub ? ['status' => $sub->state(), 'expires_at' => $sub->expires_at?->toIso8601String(), 'days_left' => $sub->daysLeft()] : null,
        ];
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
