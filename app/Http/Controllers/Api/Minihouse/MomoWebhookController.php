<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;

// Webhook (IPN) MoMo — công khai, KHÔNG qua auth:sanctum/admin.api vì MoMo gọi thẳng vào đây. Khác
// PayOsWebhookController (dùng SDK verifyPaymentWebhookData sẵn có) — MoMo không có gói PHP chính
// thức trên Packagist nên tự tính lại HMAC-SHA256 theo ĐÚNG công thức tài liệu chính thức MoMo cho
// IPN (xem InvoiceMomoService — công thức tạo request KHÁC công thức IPN, không dùng chung được).
//
// Luôn trả HTTP 204 (No Content) — MoMo coi bất kỳ mã 2xx nào là "đã nhận", không yêu cầu body cụ
// thể như VNPay. Mọi lỗi/không khớp đều chỉ log lại, không throw ra ngoài (tránh MoMo lặp lại gọi
// vô hạn vì nhận được lỗi 5xx).
class MomoWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $data = $request->all();
        $orderId = $data['orderId'] ?? null;

        $invoice = $orderId
            ? Invoice::withoutGlobalScopes()
                ->with([
                    'contract'               => fn ($q) => $q->withoutGlobalScopes(),
                    'contract.room'          => fn ($q) => $q->withoutGlobalScopes(),
                    'contract.room.building' => fn ($q) => $q->withoutGlobalScopes(),
                ])
                ->where('momo_order_id', $orderId)
                ->first()
            : null;

        $building = $invoice?->contract?->room?->building;

        if (! $invoice || ! $building?->hasOwnMomo()) {
            Log::info('MomoWebhookController: không khớp hoá đơn/toà nhà nào, bỏ qua', ['order_id' => $orderId]);

            return response()->noContent();
        }

        [, $accessKey, $secretKey] = $building->momoCredentials();

        // Công thức chữ ký IPN — KHÁC công thức lúc tạo request (thêm message/orderType/payType/
        // responseTime/resultCode/transId, BỎ ipnUrl/redirectUrl/requestType), theo đúng thứ tự chữ
        // cái tài liệu chính thức MoMo quy định cho callback.
        $rawSignature = 'accessKey=' . $accessKey
            . '&amount=' . ($data['amount'] ?? '')
            . '&extraData=' . ($data['extraData'] ?? '')
            . '&message=' . ($data['message'] ?? '')
            . '&orderId=' . ($data['orderId'] ?? '')
            . '&orderInfo=' . ($data['orderInfo'] ?? '')
            . '&orderType=' . ($data['orderType'] ?? '')
            . '&partnerCode=' . ($data['partnerCode'] ?? '')
            . '&payType=' . ($data['payType'] ?? '')
            . '&requestId=' . ($data['requestId'] ?? '')
            . '&responseTime=' . ($data['responseTime'] ?? '')
            . '&resultCode=' . ($data['resultCode'] ?? '')
            . '&transId=' . ($data['transId'] ?? '');

        $computedSignature = hash_hmac('sha256', $rawSignature, $secretKey);
        $receivedSignature = $data['signature'] ?? '';

        if (! hash_equals($computedSignature, (string) $receivedSignature)) {
            Log::warning('MomoWebhookController: chữ ký không hợp lệ, bỏ qua', [
                'order_id'    => $orderId,
                'building_id' => $building->id,
            ]);

            return response()->noContent();
        }

        if ((int) ($data['resultCode'] ?? -1) !== 0) {
            // Không phải trạng thái thành công (huỷ/hết hạn/thất bại...) — không tạo gì cả.
            return response()->noContent();
        }

        // Chặn tạo trùng nếu MoMo gọi lại IPN nhiều lần cho CÙNG 1 giao dịch (transId là mã giao
        // dịch DUY NHẤT phía MoMo cho lần thanh toán này).
        $transId = $data['transId'] ?? null;
        $note    = 'Thanh toán qua MoMo - transId: ' . $transId;

        $amount = (float) ($data['amount'] ?? 0);

        // Số tiền MoMo báo về PHẢI khớp số còn phải thu tại thời điểm này — hoá đơn có thể đã bị sửa
        // tay (giảm/tăng tổng tiền) SAU KHI khách mở link MoMo cũ, hoặc đã được thanh toán qua kênh
        // khác trước khi MoMo kịp gọi IPN. Bỏ qua kiểm tra nếu hoá đơn đã "Đã thanh toán" (tiền thật
        // vẫn có thể đã về, không nên âm thầm bỏ mất — chỉ không được TỰ Ý ghi đè số tiền sai khi còn
        // đang chờ thu), cùng nguyên tắc với VnpayIpnController.
        if (abs($amount - $invoice->remainingAmount()) > 1 && $invoice->status !== Invoice::STATUS_PAID) {
            Log::warning('MomoWebhookController: số tiền không khớp, bỏ qua', [
                'order_id'         => $orderId,
                'momo_amount'      => $amount,
                'invoice_remaining' => $invoice->remainingAmount(),
            ]);

            return response()->noContent();
        }

        // Khoá dòng Invoice (SELECT ... FOR UPDATE) trong 1 transaction cho TOÀN BỘ đoạn kiểm tra +
        // tạo bên dưới — MoMo có thể gọi lại IPN gần như CÙNG LÚC (hành vi retry chuẩn của cổng thanh
        // toán khi phản hồi chậm); nếu chỉ kiểm tra "exists()" rồi mới "create()" như trước (không
        // khoá), 2 request gần như đồng thời có thể CÙNG thấy "chưa có" rồi CÙNG tạo — ghi trùng 1
        // lần thanh toán. Khoá đảm bảo request thứ 2 phải đợi request thứ 1 commit xong mới đọc lại.
        DB::transaction(function () use ($invoice, $amount, $note, $orderId) {
            Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            $alreadyRecorded = InvoicePayment::where('invoice_id', $invoice->id)->where('note', $note)->exists();

            if ($alreadyRecorded) {
                return;
            }

            // Nghiệp vụ hiện tại CHỈ nhận ĐÚNG 1 lần thanh toán/hoá đơn (xem
            // Invoice::validateSinglePayment() — áp cho cả ghi tay ở Filament/API). Hoá đơn đã có 1
            // dòng thanh toán khác (tiền mặt/chuyển khoản tay CHƯA duyệt, hoặc đã duyệt qua cổng
            // khác) rồi mà MoMo vẫn báo về thành công — KHÔNG tự tạo thêm dòng thứ 2 (sẽ cộng dồn sai
            // amount_paid nếu dòng cũ sau này cũng được duyệt) — chỉ log cảnh báo để nhân viên tự
            // kiểm tra và xử lý tay.
            if ($invoice->payments()->exists()) {
                Log::warning('MomoWebhookController: hoá đơn đã có thanh toán khác, KHÔNG tự tạo thêm — cần nhân viên kiểm tra tay', [
                    'order_id'   => $orderId,
                    'invoice_id' => $invoice->id,
                ]);

                return;
            }

            // status=APPROVED ngay — MoMo đã xác nhận tiền thật vào tài khoản (chữ ký hợp lệ), không
            // cần "Chủ toà nhà" duyệt lại lần 2 (giống PayOS, khác tiền mặt/chuyển khoản tay do nhân
            // viên tự khai — xem InvoicePaymentObserver).
            $invoice->payments()->create([
                'amount'         => $amount,
                'paid_at'        => now(),
                'payment_method' => InvoicePayment::METHOD_TRANSFER,
                'note'           => $note,
                'status'         => InvoicePayment::STATUS_APPROVED,
                'approved_at'    => now(),
                'created_by'     => null,
            ]);
        });

        return response()->noContent();
    }
}
