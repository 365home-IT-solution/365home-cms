<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Str;
use Modules\BladeThemeV1\Support\QrCodeGenerator;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;

// Tạo link thanh toán VNPay (thẻ ATM nội địa/quốc tế, hoặc quét QR VNPAYQR trong app ngân hàng) cho
// 1 hoá đơn. Cùng nguyên tắc InvoiceMomoService — KHÔNG có "tài khoản chung", mỗi toà nhà PHẢI tự
// đăng ký merchant VNPay riêng (vnp_TmnCode/vnp_HashSecret) mới dùng được.
//
// API dùng: VNPay "Thanh toán Pay" (redirect URL), API version 2.1.0, xem
// https://sandbox.vnpayment.vn/apis/docs/thanh-toan-pay/pay.html. 2 domain HOÀN TOÀN TÁCH BIỆT —
// TmnCode/HashSecret sandbox (Building.payment_sandbox=true, tự đăng ký MIỄN PHÍ tại
// sandbox.vnpayment.vn/devreg/, không cần giấy tờ doanh nghiệp) CHỈ hoạt động trên domain
// sandbox.vnpayment.vn — TmnCode/HashSecret doanh nghiệp thật CHỈ hoạt động trên vnpayment.vn.
class InvoiceVnpayService
{
    private const PAY_URL_PRODUCTION = 'https://vnpayment.vn/paymentv2/vpcpay.html';
    private const PAY_URL_SANDBOX    = 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html';

    private const EXPIRE_MINUTES = 30;

    public static function isConfiguredFor(?Building $building): bool
    {
        return $building?->activePaymentMethod() === Building::PAYMENT_METHOD_VNPAY;
    }

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
     * @param  string|null  $returnUrl  Trang VNPay đưa khách VỀ sau khi thanh toán — mặc định trang
     *                                  Sửa hoá đơn (panel nhân viên). Portal khách thuê PHẢI truyền
     *                                  route riêng, xem giải thích ở InvoiceMomoService::createPaymentRequest().
     * @return array{payment_url: string, qr_image: string, amount: float, expired_at: string}
     */
    public static function createPaymentUrl(Invoice $invoice, string $clientIp, ?string $returnUrl = null): array
    {
        $amount = $invoice->remainingAmount();

        if ($amount <= 0) {
            throw new \RuntimeException('Hoá đơn đã thanh toán đủ, không cần tạo link thanh toán.');
        }

        $building = self::resolveBuilding($invoice);

        if (! self::isConfiguredFor($building)) {
            throw new \RuntimeException('Chưa cấu hình VNPay cho toà nhà này — vào Toà nhà > Thanh toán, chọn "VNPay" và điền đủ Mã Website (TMN Code)/Chuỗi bí mật (Hash Secret).');
        }

        [$tmnCode, $hashSecret] = $building->vnpayCredentials();

        $txnRef    = self::generateUniqueTxnRef();
        $createdAt = now();
        $expiredAt = $createdAt->copy()->addMinutes(self::EXPIRE_MINUTES);

        $params = [
            'vnp_Version'    => '2.1.0',
            'vnp_Command'    => 'pay',
            'vnp_TmnCode'    => $tmnCode,
            'vnp_Amount'     => (int) round($amount * 100),
            'vnp_CurrCode'   => 'VND',
            'vnp_TxnRef'     => $txnRef,
            // vnp_OrderInfo: bỏ dấu tiếng Việt — 1 số ngân hàng liên kết từ chối giao dịch nếu trường
            // này chứa ký tự có dấu, dù tài liệu VNPay ghi "Alphanumeric" khá mơ hồ về Unicode.
            'vnp_OrderInfo'  => self::stripDiacritics('Thanh toan hoa don thue phong so ' . $invoice->id),
            'vnp_OrderType'  => 'other',
            'vnp_Locale'     => 'vn',
            'vnp_ReturnUrl'  => $returnUrl ?? url('/minihouse-admin/invoices/' . $invoice->id . '/edit'),
            'vnp_IpAddr'     => $clientIp,
            'vnp_CreateDate' => $createdAt->format('YmdHis'),
            'vnp_ExpireDate' => $expiredAt->format('YmdHis'),
        ];

        $payUrl = $building->payment_sandbox ? self::PAY_URL_SANDBOX : self::PAY_URL_PRODUCTION;
        $paymentUrl = $payUrl . '?' . self::buildQueryWithHash($params, $hashSecret);

        $invoice->update([
            'vnpay_txn_ref'     => $txnRef,
            'vnpay_payment_url' => $paymentUrl,
            'vnpay_expired_at'  => $expiredAt,
        ]);

        return [
            'payment_url' => $paymentUrl,
            'qr_image'    => QrCodeGenerator::dataUri($paymentUrl, 260),
            'amount'      => $amount,
            'expired_at'  => $expiredAt->toIso8601String(),
        ];
    }

    // Thuật toán CHÍNH THỨC của VNPay (API v2.1.0, HMAC-SHA512) — sắp xếp field theo bảng chữ cái
    // (ksort), urlencode() TỪNG key/value (encode dấu cách thành "+", không phải rawurlencode encode
    // thành "%20" — 2 hàm cho ra chuỗi khác nhau, dùng sai hàm sẽ khiến chữ ký KHÔNG BAO GIỜ khớp),
    // nối bằng "&", rồi HMAC-SHA512 với vnp_HashSecret. Dùng CHUNG cho cả lúc tạo link (ký toàn bộ
    // param) LẪN lúc xác minh IPN (ký lại param nhận được, trừ vnp_SecureHash, so khớp).
    public static function buildQueryWithHash(array $params, string $hashSecret): string
    {
        ksort($params);

        $pairs = [];

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $pairs[] = urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        $hashData = implode('&', $pairs);
        $secureHash = hash_hmac('sha512', $hashData, $hashSecret);

        return $hashData . '&vnp_SecureHash=' . $secureHash;
    }

    public static function verifySignature(array $params, string $hashSecret): bool
    {
        $receivedHash = $params['vnp_SecureHash'] ?? null;

        if (! $receivedHash) {
            return false;
        }

        unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);

        ksort($params);

        $pairs = [];

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $pairs[] = urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        $hashData = implode('&', $pairs);
        $computedHash = hash_hmac('sha512', $hashData, $hashSecret);

        return hash_equals($computedHash, $receivedHash);
    }

    // Cùng cách VietQrService::asciiUpper() đang làm (Str::ascii() — bảng chuyển đổi thuần PHP của
    // Laravel, không phụ thuộc extension intl) — nhất quán 1 cách bỏ dấu duy nhất trong toàn module.
    private static function stripDiacritics(string $value): string
    {
        $value = Str::ascii($value);

        return preg_replace('/[^A-Za-z0-9 ]/', '', $value) ?? $value;
    }

    // vnp_TxnRef PHẢI duy nhất — VNPay từ chối tạo giao dịch mới nếu trùng mã tham chiếu CŨ CHƯA
    // hoàn tất (dù đã hết hạn). Mỗi lần bấm "Tạo lại link" đều sinh mã MỚI, không tái sử dụng mã cũ.
    private static function generateUniqueTxnRef(): string
    {
        do {
            $ref = 'MH' . now()->format('YmdHis') . random_int(100, 999);
        } while (Invoice::withoutGlobalScopes()->where('vnpay_txn_ref', $ref)->exists());

        return $ref;
    }
}
