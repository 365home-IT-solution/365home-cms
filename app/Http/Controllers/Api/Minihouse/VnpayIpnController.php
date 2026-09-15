<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Services\InvoiceVnpayService;

// IPN (Instant Payment Notification) VNPay — công khai, KHÔNG qua auth:sanctum/admin.api. KHÁC
// PayOS/MoMo (chỉ cần trả 200/204 bất kỳ) — VNPay YÊU CẦU đúng định dạng JSON {"RspCode":...,
// "Message":...} theo bảng mã họ quy định, dùng SAI mã sẽ khiến VNPay hiểu nhầm là lỗi và LẶP LẠI
// gọi IPN tối đa 10 lần/5 phút một, xem https://sandbox.vnpayment.vn/apis/docs/thanh-toan-pay/pay.html.
class VnpayIpnController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $params = $request->query();
        $txnRef = $params['vnp_TxnRef'] ?? null;

        $invoice = $txnRef
            ? Invoice::withoutGlobalScopes()
                ->with([
                    'contract'               => fn ($q) => $q->withoutGlobalScopes(),
                    'contract.room'          => fn ($q) => $q->withoutGlobalScopes(),
                    'contract.room.building' => fn ($q) => $q->withoutGlobalScopes(),
                ])
                ->where('vnpay_txn_ref', $txnRef)
                ->first()
            : null;

        $building = $invoice?->contract?->room?->building;

        if (! $invoice || ! $building?->hasOwnVnpay()) {
            return response()->json(['RspCode' => '01', 'Message' => 'Order not found']);
        }

        [, $hashSecret] = $building->vnpayCredentials();

        if (! InvoiceVnpayService::verifySignature($params, $hashSecret)) {
            Log::warning('VnpayIpnController: chữ ký không hợp lệ, bỏ qua', [
                'txn_ref'     => $txnRef,
                'building_id' => $building->id,
            ]);

            return response()->json(['RspCode' => '97', 'Message' => 'Invalid signature']);
        }

        // vnp_Amount VNPay gửi về đã nhân 100 (xem InvoiceVnpayService::createPaymentUrl) — phải
        // chia lại mới đúng đơn vị VNĐ thật để so khớp/ghi nhận.
        $amount = ((float) ($params['vnp_Amount'] ?? 0)) / 100;

        if (abs($amount - $invoice->remainingAmount()) > 1 && $invoice->status !== Invoice::STATUS_PAID) {
            // Số tiền không khớp với số còn phải thu tại thời điểm tạo link (VD hoá đơn vừa được
            // nhân viên sửa tay sau khi khách đã mở link cũ) — không tự ghi nhận sai số tiền, báo
            // VNPay biết để KHÔNG lặp lại gọi (đây là lỗi nghiệp vụ thật, gọi lại cũng vẫn sai).
            Log::warning('VnpayIpnController: số tiền không khớp, bỏ qua', [
                'txn_ref'          => $txnRef,
                'vnpay_amount'     => $amount,
                'invoice_remaining' => $invoice->remainingAmount(),
            ]);

            return response()->json(['RspCode' => '04', 'Message' => 'Invalid amount']);
        }

        $responseCode = $params['vnp_ResponseCode'] ?? null;
        $transactionStatus = $params['vnp_TransactionStatus'] ?? null;

        if ($responseCode !== '00' || $transactionStatus !== '00') {
            // Giao dịch không thành công (huỷ/thất bại...) — vẫn xác nhận đã nhận IPN (RspCode 00),
            // KHÔNG tạo thanh toán nào cả — đúng quy ước VNPay: RspCode ở đây là "đã nhận webhook",
            // không phải "giao dịch thành công".
            return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
        }

        // Chặn tạo trùng nếu VNPay gọi lại IPN nhiều lần cho CÙNG 1 giao dịch — vnp_TransactionNo là
        // mã giao dịch DUY NHẤT phía VNPay cho lần thanh toán thành công này.
        $transactionNo = $params['vnp_TransactionNo'] ?? null;
        $note = 'Thanh toán qua VNPay - transactionNo: ' . $transactionNo;

        // Khoá dòng Invoice (SELECT ... FOR UPDATE) trong 1 transaction cho TOÀN BỘ đoạn kiểm tra +
        // tạo bên dưới — VNPay có thể gọi lại IPN tối đa 10 lần/5 phút gần như CÙNG LÚC (xem comment
        // đầu file); nếu chỉ kiểm tra "exists()" rồi mới "create()" (không khoá), 2 request gần như
        // đồng thời có thể CÙNG thấy "chưa có" rồi CÙNG tạo — ghi trùng 1 lần thanh toán, cộng dồn sai
        // amount_paid. Đồng bộ với đúng cơ chế đã áp dụng ở MomoWebhookController.
        $created = DB::transaction(function () use ($invoice, $amount, $note) {
            Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            $alreadyRecorded = InvoicePayment::where('invoice_id', $invoice->id)->where('note', $note)->exists();

            if ($alreadyRecorded) {
                return false;
            }

            // Nghiệp vụ hiện tại CHỈ nhận ĐÚNG 1 lần thanh toán/hoá đơn (xem
            // Invoice::validateSinglePayment()). Hoá đơn đã có 1 dòng thanh toán khác (tiền mặt/
            // chuyển khoản tay CHƯA duyệt, hoặc đã duyệt qua cổng khác) rồi mà VNPay vẫn báo về thành
            // công — KHÔNG tự tạo thêm dòng thứ 2 (sẽ cộng dồn sai amount_paid nếu dòng cũ sau này
            // cũng được duyệt) — chỉ log cảnh báo để nhân viên tự kiểm tra và xử lý tay, cùng nguyên
            // tắc với MomoWebhookController.
            if ($invoice->payments()->exists()) {
                Log::warning('VnpayIpnController: hoá đơn đã có thanh toán khác, KHÔNG tự tạo thêm — cần nhân viên kiểm tra tay', [
                    'txn_ref'    => $invoice->vnpay_txn_ref,
                    'invoice_id' => $invoice->id,
                ]);

                return false;
            }

            // status=APPROVED ngay — VNPay đã xác nhận tiền thật (chữ ký + mã trạng thái hợp lệ),
            // không cần "Chủ toà nhà" duyệt lại lần 2 (giống PayOS/MoMo).
            $invoice->payments()->create([
                'amount'         => $amount,
                'paid_at'        => now(),
                'payment_method' => InvoicePayment::METHOD_TRANSFER,
                'note'           => $note,
                'status'         => InvoicePayment::STATUS_APPROVED,
                'approved_at'    => now(),
                'created_by'     => null,
            ]);

            return true;
        });

        if (! $created) {
            return response()->json(['RspCode' => '02', 'Message' => 'Order already confirmed']);
        }

        return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
    }
}
