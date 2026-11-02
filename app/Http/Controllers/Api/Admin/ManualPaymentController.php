<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payment\SePayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\SePayPaymentLink;

// Đối tác dùng "xác nhận thủ công" (không qua SePay / Pay2S): khách chuyển khoản vào tài khoản đối tác theo QR, đối tác xem danh sách chờ và bấm xác nhận
// đã nhận tiền → đơn được xác nhận như khi cổng báo tiền về. Đối tác chỉ thấy/xác nhận khoản của đối tác mình; Super Admin thấy tất cả.
class ManualPaymentController extends Controller
{
    public function __construct(private readonly SePayService $sePay)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $partnerId = $this->scopePartnerId($request);

        $links = $this->sePay->pendingManualLinks($partnerId)->with(['order', 'account.category'])->orderByRaw('customer_claimed_at is null')->orderBy('id')->paginate(min(100, (int) $request->integer('per_page', 20)));

        return response()->json([
            'data' => $links->getCollection()->map(fn (SePayPaymentLink $link) => $this->present($link))->values(),
            'meta' => ['current_page' => $links->currentPage(), 'last_page' => $links->lastPage(), 'per_page' => $links->perPage(), 'total' => $links->total()],
        ]);
    }

    public function confirm(Request $request, int $link): JsonResponse
    {
        $partnerId = $this->scopePartnerId($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $record = $this->sePay->pendingManualLinks($partnerId)->with(['order', 'account.category'])->find($link);

        if (! $record) {
            return response()->json(['message' => 'Không có yêu cầu thanh toán chờ xác nhận này (đã xác nhận, đã huỷ hoặc không thuộc đối tác của bạn).'], 404);
        }

        $result = $this->sePay->confirmManually($record, $request->user()->id, $data['note'] ?? null);

        if ($result['status'] !== 'processed') {
            return response()->json(['message' => $result['reason'] ?: 'Không xác nhận được khoản thanh toán.', 'status' => $result['status']], 422);
        }

        return response()->json(['message' => 'Đã xác nhận nhận tiền — đơn #' . $result['order']->order_code . ' đã được xác nhận.', 'data' => $this->present($record->fresh()->load(['order', 'account.category']))]);
    }

    /** null = mọi đối tác (Super Admin đang không chọn đối tác). Tài khoản không thuộc đối tác nào thì bị từ chối. */
    private function scopePartnerId(Request $request): ?string
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return $user->seesAllPartners() ? null : (string) $user->partner_id;
        }

        abort_if(empty($user->partner_id), 403, 'Tài khoản không thuộc đối tác nào.');

        return (string) $user->partner_id;
    }

    private function present(SePayPaymentLink $link): array
    {
        $order = $link->order ?: Order::withoutGlobalScopes()->find($link->order_id);

        return [
            'id' => $link->id,
            'order_code' => $order?->order_code,
            'kind' => $order ? $this->sePay->kindOf($order, $link) : null,
            'amount' => (int) $link->amount,
            'payment_code' => $link->paymentCode(),
            // Tài khoản ngân hàng khách được báo chuyển vào (đối tác có nhiều chi nhánh thì mỗi chi nhánh một tài khoản) để đối chiếu đúng app ngân hàng.
            'account' => $link->account ? [
                'id' => $link->account->id,
                'category_id' => $link->account->category_id,
                'branch' => $link->account->category_id ? $link->account->category?->name : null,
                'bank_code' => $link->account->bank_code,
                'account_number' => $link->account->account_number,
                'account_holder' => $link->account->account_holder,
            ] : null,
            'status' => $link->status,
            // Lúc khách bấm "Tôi đã chuyển khoản" (null = khách chưa báo). Khoản khách đã báo đứng đầu danh sách.
            'customer_claimed_at' => $link->customer_claimed_at?->toIso8601String(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
            'order_status' => $order?->status,
            'buyer_name' => $order?->buyer_name,
            'buyer_phone' => $order?->buyer_phone,
        ];
    }
}
