<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayTransaction;

// Nhật ký giao dịch SePay / Pay2S qua API — cùng dữ liệu, bộ lọc và nhãn trạng thái với CMS → "Giao dịch SePay / Pay2S" (chỉ đọc, chỉ Super Admin).
// Giao dịch "rejected" là tiền ĐÃ VÀO tài khoản đối tác nhưng đơn chưa được xác nhận (sai số tiền, chuyển trùng, đơn hết hạn...) — cần đối soát / hoàn tiền thủ công.
class SePayTransactionController extends Controller
{
    private const STATUS = [
        'processed' => 'Đã xác nhận đơn',
        'rejected' => 'Bị từ chối',
        'failed' => 'Lỗi xử lý (cổng sẽ gửi lại)',
        'received' => 'Đang xử lý',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $filters = $request->validate([
            'provider' => ['nullable', Rule::in(array_keys(PartnerSePayAccount::PROVIDERS))],
            'status' => ['nullable', Rule::in(array_keys(self::STATUS))],
            'partner_id' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SePayTransaction::query()->with('account.partner')->orderByDesc('id');

        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['partner_id'])) {
            $query->whereHas('account', fn ($q) => $q->where('partner_id', $filters['partner_id']));
        }
        if (! empty($filters['q'])) {
            $like = '%' . $filters['q'] . '%';
            $query->where(fn ($q) => $q->where('payment_code', 'like', $like)->orWhere('reference_code', 'like', $like));
        }
        if (! empty($filters['from'])) {
            $query->where('transaction_at', '>=', $filters['from'] . ' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('transaction_at', '<=', $filters['to'] . ' 23:59:59');
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 20));
        $orders = Order::withoutGlobalScopes()->whereIn('id', $page->getCollection()->pluck('order_id')->filter()->unique())->pluck('order_code', 'id');

        $page->getCollection()->transform(fn (SePayTransaction $tx) => $this->present($tx, $orders[$tx->order_id] ?? null));

        return response()->json($page);
    }

    public function show(Request $request, int $transaction): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $tx = SePayTransaction::query()->with('account.partner')->findOrFail($transaction);
        $orderCode = $tx->order_id ? Order::withoutGlobalScopes()->where('id', $tx->order_id)->value('order_code') : null;

        // Dữ liệu gốc cổng gửi về (như nút "Chi tiết" ở CMS).
        return response()->json(['data' => $this->present($tx, $orderCode) + ['payload' => $tx->payload]]);
    }

    private function present(SePayTransaction $tx, ?string $orderCode): array
    {
        return [
            'id' => $tx->id,
            'provider' => $tx->provider ?: PartnerSePayAccount::PROVIDER_SEPAY,
            'provider_transaction_id' => $tx->sepay_id,
            'partner' => $tx->account?->partner ? ['id' => $tx->account->partner->id, 'name' => $tx->account->partner->name] : null,
            'account_number' => $tx->account?->account_number,
            'order_id' => $tx->order_id,
            'order_code' => $orderCode !== null ? (string) $orderCode : null,
            'payment_code' => $tx->payment_code,
            'amount' => (int) $tx->amount,
            'reference_code' => $tx->reference_code,
            'transaction_at' => $tx->transaction_at?->toIso8601String(),
            'status' => $tx->status,
            'status_label' => self::STATUS[$tx->status] ?? $tx->status,
            'failure_reason' => $tx->failure_reason,
            'created_at' => $tx->created_at?->toIso8601String(),
        ];
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Chỉ Super Admin được xem giao dịch SePay / Pay2S.');
    }
}
