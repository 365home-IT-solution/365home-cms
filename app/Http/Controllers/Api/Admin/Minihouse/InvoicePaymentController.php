<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Services\InvoiceContentRenderer;
use Modules\Minihouse\App\Services\InvoicePaymentAllocationService;

// Ghi nhận thanh toán cho 1 hoá đơn — mỗi lần tạo/sửa/xoá ở đây tự kích InvoicePaymentObserver,
// đồng bộ lại Invoice.amount_paid/paid_at/status VÀ tự tạo/cập nhật dòng "Thu" tương ứng trong sổ
// Thu Chi (Transaction.invoice_payment_id) — không cần tự làm lại logic đó ở controller này.
class InvoicePaymentController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/invoices/{invoiceId}/payments
    public function index(Request $request, int $invoiceId): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_invoices')) {
            return response()->json(['message' => 'Không có quyền xem hoá đơn.'], 403);
        }

        $invoice = $this->findAllowedInvoice($request, $invoiceId);

        if (! $invoice) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        return response()->json(['data' => $invoice->payments()->orderByDesc('paid_at')->get()->map(fn (InvoicePayment $p) => $this->toItem($p))]);
    }

    // POST /api/admin/minihouse/invoices/{invoiceId}/payments
    public function store(Request $request, int $invoiceId): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_invoices')) {
            return response()->json(['message' => 'Không có quyền ghi nhận thanh toán.'], 403);
        }

        $invoice = $this->findAllowedInvoice($request, $invoiceId);

        if (! $invoice) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        $data = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'paid_at'        => 'required|date',
            'payment_method' => ['required', Rule::in([InvoicePayment::METHOD_CASH, InvoicePayment::METHOD_TRANSFER, InvoicePayment::METHOD_OTHER])],
            'note'           => 'nullable|string',
        ]);

        $amount    = (float) $data['amount'];
        $totalOwed = InvoiceContentRenderer::totalOwed($invoice);

        // Khách đưa ĐỦ tiền gồm cả nợ tháng trước (số tiền đúng bằng totalOwed(), khác hẳn total_amount
        // của riêng hoá đơn này) — chia thành nhiều khoản PENDING qua InvoicePaymentAllocationService
        // (trả nợ cũ nhất trước), mỗi khoản vẫn cần "Chủ toà nhà" duyệt riêng như luồng cũ, xem
        // approve() bên dưới. Không áp dụng nếu hoá đơn này không có nợ cũ (totalOwed() == total_amount)
        // — khi đó rơi thẳng xuống nhánh 1-hoá-đơn như trước, không đổi hành vi.
        if (abs($amount - $totalOwed) <= 0.01 && abs($totalOwed - (float) $invoice->total_amount) > 0.01) {
            $result = InvoicePaymentAllocationService::allocate(
                $invoice,
                $amount,
                $data['payment_method'],
                $data['note'] ?? 'Ghi nhận thanh toán gộp nợ cũ',
                InvoicePayment::STATUS_PENDING,
                $request->user()->id,
                \Illuminate\Support\Carbon::parse($data['paid_at']),
            );

            if (abs($result['unallocated']) > 1) {
                return response()->json([
                    'message' => 'Còn ' . number_format($result['unallocated'], 0, ',', '.') . 'đ chưa gán được vào hoá đơn nào (có thể do 1 hoá đơn cũ đang "Thanh toán 1 phần") — vui lòng kiểm tra lại số tiền hoặc ghi nhận riêng khoản đó.',
                ], 422);
            }

            return response()->json([
                'data'    => collect($result['payments'])->map(fn (InvoicePayment $p) => $this->toItem($p)),
                'message' => 'Đã ghi nhận ' . count($result['payments']) . ' khoản thanh toán (chờ Chủ toà nhà duyệt riêng từng khoản), trả nợ cũ nhất trước.',
            ], 201);
        }

        // Chỉ nhận ĐÚNG 1 lần thanh toán, đủ 100% tổng hoá đơn — dùng chung quy tắc với InvoiceForm
        // (Filament), xem Invoice::validateSinglePayment().
        $error = $invoice->validateSinglePayment($amount);

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $data['invoice_id'] = $invoice->id;
        $data['created_by'] = $request->user()->id;
        // Ghi nhận qua API (tiền mặt/chuyển khoản do nhân viên tự khai) LUÔN ở trạng thái 'pending' —
        // CHƯA cộng vào Invoice.amount_paid cho tới khi "Chủ toà nhà" duyệt qua POST .../approve bên
        // dưới (xem InvoicePaymentObserver::resyncInvoice, giống hệt luồng ghi nhận trên Filament).
        $data['status'] = InvoicePayment::STATUS_PENDING;

        $payment = InvoicePayment::create($data);

        return response()->json(['data' => $this->toItem($payment), 'invoice' => [
            'amount_paid' => $invoice->fresh()->amount_paid,
            'status'      => $invoice->fresh()->status,
            'remaining'   => $invoice->fresh()->remainingAmount(),
        ]], 201);
    }

    // POST /api/admin/minihouse/invoices/{invoiceId}/payments/{paymentId}/approve
    // Riêng cho "Chủ toà nhà" (quyền approve_invoice_payments, KHÁC update_invoices — nhân viên ghi
    // nhận được nhưng không tự duyệt được chính mình) — xác nhận 1 khoản 'pending' là có thật.
    public function approve(Request $request, int $invoiceId, int $paymentId): JsonResponse
    {
        if (! $this->hasPermission($request, 'approve_invoice_payments')) {
            return response()->json(['message' => 'Không có quyền duyệt thanh toán.'], 403);
        }

        $invoice = $this->findAllowedInvoice($request, $invoiceId);

        if (! $invoice) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        $payment = $invoice->payments()->where('status', InvoicePayment::STATUS_PENDING)->find($paymentId);

        if (! $payment) {
            return response()->json(['message' => 'Không tìm thấy khoản thanh toán đang chờ duyệt này.'], 404);
        }

        // Cùng lý do EditInvoice::approvePayment() bên Filament — nghiệp vụ chỉ nhận ĐÚNG 1 lần
        // thanh toán/hoá đơn; nếu hoá đơn đã có 1 khoản KHÁC được duyệt rồi (VD webhook cổng thanh
        // toán tự duyệt trước) thì không cho duyệt thêm khoản pending cũ này nữa, tránh cộng dồn sai.
        if ($invoice->payments()->where('status', InvoicePayment::STATUS_APPROVED)->exists()) {
            return response()->json(['message' => 'Hoá đơn này đã có 1 khoản thanh toán khác được duyệt rồi — vui lòng kiểm tra lại trước khi duyệt khoản này.'], 422);
        }

        $payment->update([
            'status'      => InvoicePayment::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->toItem($payment->fresh()), 'invoice' => [
            'amount_paid' => $invoice->fresh()->amount_paid,
            'status'      => $invoice->fresh()->status,
            'remaining'   => $invoice->fresh()->remainingAmount(),
        ]]);
    }

    // DELETE /api/admin/minihouse/invoices/{invoiceId}/payments/{paymentId}
    public function destroy(Request $request, int $invoiceId, int $paymentId): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_invoices')) {
            return response()->json(['message' => 'Không có quyền xoá thanh toán.'], 403);
        }

        $invoice = $this->findAllowedInvoice($request, $invoiceId);

        if (! $invoice) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        $payment = $invoice->payments()->find($paymentId);

        if (! $payment) {
            return response()->json(['message' => 'Không tìm thấy lần thanh toán này.'], 404);
        }

        // Đối xứng với chặn xoá HOÁ ĐƠN đã có (InvoiceObserver::deleting() ném
        // CannotDeletePaidInvoiceException khi có khoản duyệt) — thiếu chặn tương tự ở CHÍNH endpoint
        // xoá payment này thì vẫn xoá thẳng được khoản đã duyệt qua đây, xoá THẬT (không SoftDeletes)
        // kéo theo cascade xoá luôn dòng "Thu" liên kết trong sổ Thu Chi, mất vĩnh viễn bằng chứng đã
        // thu tiền thật.
        if ($payment->status === InvoicePayment::STATUS_APPROVED) {
            return response()->json(['message' => 'Khoản thanh toán này đã được duyệt — không thể xoá để tránh mất dữ liệu sổ Thu Chi.'], 422);
        }

        $payment->delete();

        return response()->json(['message' => 'Đã xoá lần thanh toán.']);
    }

    // Contract/Room dùng SoftDeletes riêng — eager-load thường vẫn áp scope đó ở quan hệ lồng nhau,
    // nên 1 hợp đồng bị xoá mềm sẽ làm $invoice->contract trả về null, kéo theo isBuildingAllowed()
    // CHẶN NHẦM quyền xem/ghi nhận thanh toán cho 1 hoá đơn lịch sử hợp lệ (cùng lỗi lớp đã gặp và
    // sửa ở InvoiceController/InvoiceContentRenderer/InvoicePrintController/InvoicePayOsService).
    private function findAllowedInvoice(Request $request, int $invoiceId): ?Invoice
    {
        $invoice = Invoice::withoutGlobalScopes()
            ->with([
                'contract'      => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->find($invoiceId);

        if (! $invoice || ! $this->isBuildingAllowed($request, $invoice->contract?->room?->building_id)) {
            return null;
        }

        return $invoice;
    }

    private function toItem(InvoicePayment $payment): array
    {
        return [
            'id'             => $payment->id,
            'invoice_id'     => $payment->invoice_id,
            'amount'         => $payment->amount,
            'paid_at'        => $payment->paid_at?->toDateString(),
            'payment_method' => $payment->payment_method,
            'note'           => $payment->note,
            'status'         => $payment->status,
            'approved_at'    => $payment->approved_at?->toIso8601String(),
            'approved_by'    => $payment->approved_by,
            'created_by'     => $payment->created_by,
        ];
    }
}
