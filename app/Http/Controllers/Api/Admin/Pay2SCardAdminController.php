<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payment\Pay2SCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Entities\Pay2SCardPayment;

// Thanh toán thẻ Pay2S qua API admin — cùng chức năng với trang CMS "Pay2S — thanh toán thẻ" và danh sách "Giao dịch thẻ Pay2S". Chỉ Super Admin.
// Khoá bí mật chỉ GHI, không bao giờ trả ra (chỉ báo đã có hay chưa).
class Pay2SCardAdminController extends Controller
{
    public function __construct(private readonly Pay2SCardService $card)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['data' => $this->card->status()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $input = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'sandbox_only' => ['sometimes', 'boolean'],
            'partner_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'connection_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'access_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'secret_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'merchant_origin' => ['sometimes', 'nullable', 'url', 'max:255'],
            'api_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $status = $this->card->update($input);

        return response()->json(['message' => 'Đã lưu — thanh toán thẻ đang ' . ($status['enabled'] ? 'BẬT' : 'TẮT') . '.', 'data' => $status]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $result = $this->card->testConnection();

        return response()->json(['ok' => $result['ok'], 'message' => $result['message']]);
    }

    // PUT /api/admin/pay2s-card/enabled — nút BẬT/TẮT thanh toán thẻ. Tắt: khách và admin không thấy gì về thẻ (như chưa có tính năng); phiên đã tạo vẫn được xác nhận nếu tiền về.
    public function toggle(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $status = $this->card->setEnabled((bool) $data['enabled']);

        return response()->json(['message' => 'Thanh toán thẻ đang ' . ($status['enabled'] ? 'BẬT' : 'TẮT') . '.', 'data' => $status]);
    }

    // GET /api/admin/pay2s-card/payments/{id} — chi tiết 1 phiên kèm dữ liệu Pay2S gửi về (đã bỏ accessKey và chữ ký).
    public function showPayment(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['data' => $this->payload(Pay2SCardPayment::query()->with('order:id,order_code')->findOrFail($id), true)]);
    }

    // POST /api/admin/pay2s-card/payments/{id}/sync — hỏi lại Pay2S trạng thái 1 phiên đang chờ (webhook chậm/lỡ).
    public function syncPayment(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $payment = Pay2SCardPayment::query()->findOrFail($id);

        if ($payment->status !== Pay2SCardPayment::PENDING) {
            return response()->json(['message' => 'Chỉ đồng bộ được phiên đang chờ thanh toán.'], 422);
        }

        return response()->json(['data' => $this->payload($this->card->sync($payment)->load('order:id,order_code'), true)]);
    }

    // POST /api/admin/pay2s-card/payments/{id}/resolve — body: note (tuỳ chọn). Khoản "review" đã hoàn tiền / xử lý xong thủ công.
    public function resolvePayment(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:490']]);
        $payment = Pay2SCardPayment::query()->findOrFail($id);

        if (! $this->card->resolve($payment, (string) $request->user()->id, $data['note'] ?? null)) {
            return response()->json(['message' => 'Chỉ đánh dấu đã xử lý được khoản đang ở trạng thái cần hoàn tiền / đối soát.'], 422);
        }

        return response()->json(['message' => 'Đã đánh dấu đã xử lý.', 'data' => $this->payload($payment->fresh()->load('order:id,order_code'), true)]);
    }

    // Danh sách phiên thanh toán thẻ để đối soát; ?status=review = tiền đã thu nhưng đơn không nhận được → hoàn tiền thủ công.
    public function payments(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $request->validate(['status' => ['nullable', 'in:pending,paid,review,resolved,failed,cancelled,expired'], 'order_code' => ['nullable', 'string', 'max:50'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $page = Pay2SCardPayment::query()->with('order:id,order_code')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('order_code'), fn ($q) => $q->whereHas('order', fn ($o) => $o->where('order_code', $request->input('order_code'))))
            ->latest('id')->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Pay2SCardPayment $p) => $this->payload($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    private function payload(Pay2SCardPayment $p, bool $detail = false): array
    {
        return [
            'id' => $p->id, 'order_code' => $p->order?->order_code, 'kind' => $p->kind, 'card_brand' => $p->card_brand, 'amount' => (int) $p->amount,
            'status' => $p->status, 'environment' => $p->environment, 'failure_reason' => $p->failure_reason, 'pay2s_order_id' => $p->pay2s_order_id,
            'trans_id' => $p->trans_id, 'paid_at' => $p->paid_at?->toIso8601String(), 'created_at' => $p->created_at?->toIso8601String(),
            'resolved_at' => $p->resolved_at?->toIso8601String(), 'resolved_by' => $p->resolved_by, 'resolution_note' => $p->resolution_note,
        ] + ($detail ? ['expires_at' => $p->expires_at?->toIso8601String(), 'raw' => $p->safePayload()] : []);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Chỉ Super Admin được xem hoặc sửa cấu hình thanh toán thẻ.');
    }
}
