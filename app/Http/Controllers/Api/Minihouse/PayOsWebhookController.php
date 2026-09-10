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
use Modules\Minihouse\App\Services\InvoicePayOsService;
use PayOS\PayOS;

// Webhook công khai (KHÔNG qua auth:sanctum/admin.api). Có 2 nguồn gọi tới route này:
//  1. PayOS gọi TRỰC TIẾP — khi hoá đơn thuộc 1 toà nhà có TÀI KHOẢN PAYOS RIÊNG (Building::
//     hasOwnPayOs()), chủ toà tự đăng ký URL route này trên đúng dashboard.payos.vn của tài khoản
//     riêng đó.
//  2. Home tự chuyển tiếp — khi dùng tài khoản PayOS CHUNG (dùng chung với Home): PayOS chỉ cho
//     đăng ký 1 URL webhook duy nhất/tài khoản, URL đã đăng ký là webhook của Home
//     (Modules\Payment\...\PaymentController::handlePayOSWebhook), Home tự nhận diện orderCode bắt
//     đầu bằng số 9 rồi gọi thẳng self::processVerifiedData() bên dưới (đã tự verify chữ ký bằng
//     đúng checksum key chung trước khi gọi, KHÔNG qua route này).
// Vì mỗi toà nhà có thể dùng 1 checksum key KHÁC NHAU (riêng hoặc chung), phải tra ra được orderCode
// -> hoá đơn -> toà nhà TRƯỚC khi verify, để biết dùng đúng checksum key nào — orderCode tự nó không
// phải bí mật (không xác thực được gì nếu không có chữ ký), chỉ dùng để CHỌN đúng bộ khoá kiểm tra.
// Luôn trả 200 (kể cả khi không xử lý được gì) để PayOS không lặp lại gọi vô hạn — mọi lỗi/không
// khớp đều chỉ log lại, không throw ra ngoài.
class PayOsWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $orderCode = $request->input('data.orderCode');

        $invoice = $orderCode
            ? Invoice::withoutGlobalScopes()->with('contract.room.building')->where('payos_order_code', $orderCode)->first()
            : null;

        $building = $invoice?->contract?->room?->building;

        if (! InvoicePayOsService::isConfiguredFor($building)) {
            return response()->json(['message' => 'PayOS not configured']);
        }

        [$clientId, $apiKey, $checksumKey] = InvoicePayOsService::resolveCredentials($building);

        $payOS = new PayOS($clientId, $apiKey, $checksumKey);

        // PayOS tự gọi 1 lần webhook "test" (orderCode giả) khi đăng ký URL — verifyPaymentWebhookData
        // vẫn xác minh chữ ký hợp lệ cho lần test đó (dùng tài khoản CHUNG vì orderCode giả không
        // khớp hoá đơn nào), chỉ đơn giản không khớp được hoá đơn nào ở bước dưới, vẫn trả 200 bình
        // thường.
        try {
            $data = $payOS->verifyPaymentWebhookData($request->all());
        } catch (\Throwable $e) {
            Log::warning('Minihouse PayOS webhook: chữ ký không hợp lệ, bỏ qua', [
                'order_code' => $orderCode,
                'building_id' => $building?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'invalid signature']);
        }

        self::processVerifiedData($data);

        return response()->json(['message' => 'ok']);
    }

    // Xử lý dữ liệu webhook ĐÃ được xác minh chữ ký hợp lệ — tách riêng để dùng CHUNG cho cả route
    // webhook độc lập ở trên (verify qua SDK) VÀ webhook chính của Home (Modules\Payment\...\
    // PaymentController::handlePayOSWebhook, tự verify chữ ký kiểu riêng của Home bằng CÙNG 1
    // checksum key trước khi gọi hàm này — không cần verify lại lần 2). Trả về true nếu khớp được 1
    // hoá đơn MiniHouse (dù đã xử lý hay đã ghi nhận từ trước), false nếu orderCode không phải của
    // MiniHouse — để Home biết có nên tự báo "Order not found" hay không.
    public static function processVerifiedData(array $data): bool
    {
        $orderCode = $data['orderCode'] ?? null;

        // orderCode do InvoicePayOsService sinh LUÔN bắt đầu bằng số 9 (9 chữ số) — orderCode của
        // Order bên Home tối đa 8 chữ số, không bao giờ khớp nhầm sang đây.
        $invoice = Invoice::withoutGlobalScopes()->where('payos_order_code', $orderCode)->first();

        if (! $invoice) {
            return false;
        }

        if (($data['code'] ?? null) !== '00') {
            // Không phải trạng thái thanh toán thành công (huỷ/hết hạn...) — không tạo gì cả, hoá
            // đơn vẫn giữ nguyên trạng thái, nhân viên tạo mã QR mới nếu khách muốn thử lại.
            return true;
        }

        $amount = (float) ($data['amount'] ?? 0);

        // Số tiền PayOS báo về PHẢI khớp số còn phải thu tại thời điểm này — cùng lý do/ngoại lệ với
        // VnpayIpnController (hoá đơn có thể đã bị sửa tay hoặc đã thanh toán qua kênh khác trước khi
        // PayOS kịp gọi webhook).
        if (abs($amount - $invoice->remainingAmount()) > 1 && $invoice->status !== Invoice::STATUS_PAID) {
            Log::warning('Minihouse PayOS webhook: số tiền không khớp, bỏ qua', [
                'order_code'        => $orderCode,
                'payos_amount'      => $amount,
                'invoice_remaining' => $invoice->remainingAmount(),
            ]);

            return true;
        }

        // Đánh dấu theo đúng "reference" PayOS trả về — chặn tạo trùng nếu PayOS gọi lại webhook
        // nhiều lần cho CÙNG 1 giao dịch (họ có cơ chế tự retry khi chưa nhận được response 200).
        $reference = $data['reference'] ?? $data['paymentLinkId'] ?? (string) $orderCode;
        $note      = 'Thanh toán qua PayOS (QR) - ref: ' . $reference;

        // Khoá dòng Invoice trong 1 transaction cho cả đoạn kiểm tra + tạo — tránh 2 lần gọi webhook
        // gần như đồng thời (PayOS tự retry khi chưa nhận phản hồi kịp) cùng vượt qua exists() rồi
        // cùng tạo trùng 1 lần thanh toán, xem giải thích đầy đủ ở MomoWebhookController.
        DB::transaction(function () use ($invoice, $amount, $note, $orderCode) {
            Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            $alreadyRecorded = InvoicePayment::where('invoice_id', $invoice->id)->where('note', $note)->exists();

            if ($alreadyRecorded) {
                return;
            }

            // Nghiệp vụ hiện tại CHỈ nhận ĐÚNG 1 lần thanh toán/hoá đơn (xem
            // Invoice::validateSinglePayment()) — hoá đơn đã có 1 dòng thanh toán khác rồi thì KHÔNG
            // tự tạo thêm dòng thứ 2 dù PayOS báo thành công, tránh cộng dồn sai amount_paid.
            if ($invoice->payments()->exists()) {
                Log::warning('Minihouse PayOS webhook: hoá đơn đã có thanh toán khác, KHÔNG tự tạo thêm — cần nhân viên kiểm tra tay', [
                    'order_code' => $orderCode,
                    'invoice_id' => $invoice->id,
                ]);

                return;
            }

            // Tạo InvoicePayment THẬT — InvoicePaymentObserver tự đồng bộ lại Invoice.amount_paid/
            // status VÀ tự tạo dòng "Thu" tương ứng trong sổ Thu Chi, không cần tự làm lại ở đây (xem
            // InvoicePaymentObserver, MinihouseServiceProvider::boot()).
            // status=APPROVED ngay lập tức — khác thanh toán tiền mặt/chuyển khoản do nhân viên tự
            // khai (cần Chủ toà nhà duyệt thêm, xem InvoicePaymentObserver), tiền qua PayOS đã được
            // chính PayOS xác nhận thật sự vào tài khoản (webhook có chữ ký hợp lệ), không cần duyệt
            // lại lần 2.
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

        return true;
    }
}
