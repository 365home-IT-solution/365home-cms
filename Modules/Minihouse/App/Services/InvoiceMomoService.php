<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\BladeThemeV1\Support\QrCodeGenerator;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;

// Tạo link/QR thanh toán MoMo (ví MoMo, quét QR hoặc mở app) cho 1 hoá đơn. KHÁC PayOS (xem
// InvoicePayOsService): MiniHouse KHÔNG có "tài khoản MoMo chung" để rơi về — Home chưa từng tích
// hợp cổng thu tiền MoMo (chỉ có field SĐT MoMo của Partner để CHI HỘ hoa hồng, không phải merchant
// thu tiền) — mỗi toà nhà PHẢI tự đăng ký tài khoản MoMo Business riêng và khai báo đủ 3 field ở
// BuildingForm mới dùng được, không có phương án dự phòng nào khác ngoài VietQR tĩnh.
//
// API dùng: MoMo "Cổng thanh toán" — one-time payment (captureWallet), xem
// https://developers.momo.vn/v3/docs/payment/api/wallet/onetime/. 2 domain HOÀN TOÀN TÁCH BIỆT —
// bộ khoá test (Building.payment_sandbox=true) CHỈ hoạt động trên test-payment.momo.vn, bộ khoá
// doanh nghiệp thật CHỈ hoạt động trên payment.momo.vn — gọi sai domain sẽ bị MoMo từ chối thẳng,
// không phải lỗi signature.
class InvoiceMomoService
{
    private const ENDPOINT_PRODUCTION = 'https://payment.momo.vn/v2/gateway/api/create';
    private const ENDPOINT_SANDBOX    = 'https://test-payment.momo.vn/v2/gateway/api/create';

    // Bộ test credentials CÔNG KHAI do chính MoMo phát hành trong tài liệu chính thức cho lập trình
    // viên dùng thử TRƯỚC khi có tài khoản doanh nghiệp thật (không phải bí mật riêng của MiniHouse)
    // — điền sẵn 3 giá trị này + bật "Chế độ thử nghiệm" ở BuildingForm là test được ngay, không cần
    // chờ đăng ký doanh nghiệp xong.
    public const SANDBOX_PARTNER_CODE = 'MOMO';
    public const SANDBOX_ACCESS_KEY   = 'F8BBA842ECF85';
    public const SANDBOX_SECRET_KEY   = 'K951B6PE1waDMi640xX08PD3vg6EkVlz';

    private const EXPIRE_MINUTES = 30;

    public static function isConfiguredFor(?Building $building): bool
    {
        return $building?->activePaymentMethod() === Building::PAYMENT_METHOD_MOMO;
    }

    // KHÔNG dùng $invoice->contract?->room?->building (quan hệ mặc định) — hợp đồng/phòng đã xoá
    // mềm sẽ khiến $building = null do global scope SoftDeletes, cùng lỗi lớp đã gặp ở
    // InvoicePayOsService::createQr().
    private static function resolveBuilding(Invoice $invoice): ?Building
    {
        return $invoice->contract_id
            ? Contract::withoutGlobalScopes()
                ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($invoice->contract_id)
                ?->room?->building
            : null;
    }

    /**
     * @param  string|null  $redirectUrl  Trang MoMo đưa khách VỀ sau khi thanh toán xong trên app —
     *                                    mặc định trang Sửa hoá đơn (panel nhân viên) cho các nơi gọi
     *                                    cũ (EditInvoice). Portal khách thuê (TenantPortalController)
     *                                    PHẢI truyền route riêng của Portal, nếu không khách thanh
     *                                    toán xong sẽ bị đưa về thẳng trang đăng nhập admin (khác
     *                                    guard, khách không có quyền vào).
     * @return array{pay_url: string, qr_image: string, amount: float, expired_at: string}
     */
    public static function createPaymentRequest(Invoice $invoice, ?string $redirectUrl = null): array
    {
        $amount = $invoice->remainingAmount();

        if ($amount <= 0) {
            throw new \RuntimeException('Hoá đơn đã thanh toán đủ, không cần tạo link thanh toán.');
        }

        // MoMo yêu cầu tối thiểu 1.000đ, tối đa 50.000.000đ cho 1 giao dịch — báo rõ lý do thay vì để
        // MoMo trả lỗi resultCode chung chung khó hiểu cho nhân viên.
        if ($amount < 1000) {
            throw new \RuntimeException('MoMo chỉ nhận giao dịch từ 1.000đ trở lên.');
        }

        if ($amount > 50000000) {
            throw new \RuntimeException('MoMo chỉ nhận giao dịch tối đa 50.000.000đ — vui lòng thu bằng hình thức khác cho hoá đơn này.');
        }

        $building = self::resolveBuilding($invoice);

        if (! self::isConfiguredFor($building)) {
            throw new \RuntimeException('Chưa cấu hình MoMo cho toà nhà này — vào Toà nhà > Thanh toán, chọn "MoMo" và điền đủ Partner Code/Access Key/Secret Key.');
        }

        [$partnerCode, $accessKey, $secretKey] = $building->momoCredentials();

        $orderId    = self::generateUniqueOrderId();
        $requestId  = $orderId . '-' . random_int(1000, 9999);
        $amountInt  = (int) round($amount);
        $orderInfo  = substr('Thanh toan hoa don thue phong #' . $invoice->id, 0, 255);
        $redirectUrl = $redirectUrl ?? url('/minihouse-admin/invoices/' . $invoice->id . '/edit');
        $ipnUrl      = route('api.minihouse.webhook.momo');
        $requestType = 'captureWallet';
        $extraData   = '';
        $lang        = 'vi';

        // Thứ tự field trong chuỗi ký PHẢI ĐÚNG THEO BẢNG CHỮ CÁI y hệt tài liệu chính thức MoMo —
        // sai thứ tự hoặc thiếu 1 field đều khiến signature không khớp, MoMo từ chối request với lỗi
        // "Chữ ký không hợp lệ" (resultCode 1010) mà không giải thích rõ chỗ nào sai.
        $rawSignature = "accessKey={$accessKey}&amount={$amountInt}&extraData={$extraData}&ipnUrl={$ipnUrl}"
            . "&orderId={$orderId}&orderInfo={$orderInfo}&partnerCode={$partnerCode}&redirectUrl={$redirectUrl}"
            . "&requestId={$requestId}&requestType={$requestType}";

        $signature = hash_hmac('sha256', $rawSignature, $secretKey);

        $endpoint = $building->payment_sandbox ? self::ENDPOINT_SANDBOX : self::ENDPOINT_PRODUCTION;

        $response = Http::timeout(30)->post($endpoint, [
            'partnerCode' => $partnerCode,
            'partnerName' => 'MiniHouse',
            'requestId'   => $requestId,
            'amount'      => $amountInt,
            'orderId'     => $orderId,
            'orderInfo'   => $orderInfo,
            'redirectUrl' => $redirectUrl,
            'ipnUrl'      => $ipnUrl,
            'extraData'   => $extraData,
            'requestType' => $requestType,
            'signature'   => $signature,
            'lang'        => $lang,
        ]);

        $result = $response->json();

        if (! $response->successful() || (int) ($result['resultCode'] ?? -1) !== 0) {
            Log::warning('InvoiceMomoService: tạo link thanh toán thất bại', [
                'invoice_id' => $invoice->id,
                'building_id' => $building->id,
                'response'   => $result,
            ]);

            throw new \RuntimeException('MoMo từ chối tạo link thanh toán: ' . ($result['message'] ?? 'Lỗi không xác định'));
        }

        $payUrl    = $result['payUrl'] ?? '';
        // qrCodeUrl KHÔNG PHẢI url ảnh — là CHUỖI PAYLOAD riêng của MoMo, PHẢI tự vẽ thành ảnh QR
        // (giống payos_qr_code khác payos_checkout_url của PayOS). Nhầm dùng payUrl (link web) để vẽ
        // QR khiến app MoMo/camera quét báo "Thông tin không hợp lệ" — đã xác nhận đúng lỗi này qua
        // báo cáo thực tế 2026-09-09, sửa lại dùng đúng qrCodeUrl.
        $qrCode    = $result['qrCodeUrl'] ?? '';
        $expiredAt = now()->addMinutes(self::EXPIRE_MINUTES);

        $invoice->update([
            'momo_order_id'   => $orderId,
            'momo_qr_code'    => $qrCode,
            'momo_pay_url'    => $payUrl,
            'momo_expired_at' => $expiredAt,
        ]);

        return [
            'pay_url'    => $payUrl,
            'qr_image'   => filled($qrCode) ? QrCodeGenerator::dataUri($qrCode, 260) : '',
            'amount'     => $amount,
            'expired_at' => $expiredAt->toIso8601String(),
        ];
    }

    // orderId của MoMo là STRING (khác PayOS dùng số nguyên) — tiền tố "MH" (MiniHouse) để không bao
    // giờ trùng orderId của module khác nếu sau này Home cũng tích hợp MoMo, dù momo_order_id đang
    // lưu ở bảng RIÊNG của MiniHouse nên về lý thuyết không cần — vẫn giữ tiền tố cho rõ ràng khi
    // nhìn log/dashboard MoMo Business (gộp chung nhiều merchant).
    private static function generateUniqueOrderId(): string
    {
        do {
            $id = 'MH' . now()->format('YmdHis') . random_int(100, 999);
        } while (Invoice::withoutGlobalScopes()->where('momo_order_id', $id)->exists());

        return $id;
    }
}
