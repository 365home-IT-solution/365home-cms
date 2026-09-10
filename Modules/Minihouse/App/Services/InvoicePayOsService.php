<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\BladeThemeV1\Support\QrCodeGenerator;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use PayOS\PayOS;

// Tạo mã QR PayOS để khách chuyển khoản trực tiếp cho 1 hoá đơn. Ưu tiên TÀI KHOẢN PAYOS RIÊNG của
// chủ toà nhà (Building::hasOwnPayOs()) nếu có khai báo — tiền vào thẳng tài khoản của chủ toà, tự
// xác nhận qua webhook, KHÔNG cần "Chi hộ" chuyển lại. Toà nào chưa khai báo thì rơi về tài khoản
// PayOS CHUNG của hệ thống (đọc qua Config::get('payos.*'), tự nạp từ Modules\Payment\Entities\
// PaymentConfiguration bởi Modules\Payment\Providers\PaymentServiceProvider::boot() — dùng chung với
// Order bên Home).
class InvoicePayOsService
{
    // Ngưỡng thời gian QR còn hiệu lực để khách quét — hết hạn thì tạo mới, PayOS tự huỷ link cũ
    // phía họ, không cần app này tự dọn.
    private const EXPIRE_MINUTES = 30;

    // "Tài khoản PayOS chung" (không riêng theo toà nào) đã được cấu hình đủ chưa.
    public static function isConfigured(): bool
    {
        return filled(Config::get('payos.client_id'))
            && filled(Config::get('payos.api_key'))
            && filled(Config::get('payos.checksum_key'));
    }

    // Toà nhà này CÓ dùng được PayOS không — hoặc đã CHỌN kiểu thanh toán "PayOS riêng" và điền đủ
    // thông tin (Building::activePaymentMethod()), hoặc rơi về được tài khoản chung đã cấu hình đủ.
    // Cố tình KHÔNG tự dùng tài khoản riêng của toà chỉ vì 3 field đó có dữ liệu — phải đúng lựa
    // chọn tường minh ở BuildingForm mới tính, tránh dùng nhầm khi nhân viên đổi ý sang VietQR mà
    // quên xoá các ô PayOS cũ.
    public static function isConfiguredFor(?Building $building): bool
    {
        return $building?->activePaymentMethod() === Building::PAYMENT_METHOD_PAYOS || self::isConfigured();
    }

    /**
     * @return array{0: string, 1: string, 2: string} [client_id, api_key, checksum_key] — ưu tiên
     *   tài khoản riêng của toà nhà (nếu đã CHỌN kiểu "PayOS riêng"), rơi về tài khoản chung nếu
     *   không.
     */
    public static function resolveCredentials(?Building $building): array
    {
        if ($building?->activePaymentMethod() === Building::PAYMENT_METHOD_PAYOS) {
            return $building->payOsCredentials();
        }

        return [
            Config::get('payos.client_id'),
            Config::get('payos.api_key'),
            Config::get('payos.checksum_key'),
        ];
    }

    /**
     * @param  string|null  $returnUrl  Trang PayOS đưa khách VỀ sau khi thanh toán/huỷ — mặc định
     *                                  trang Sửa hoá đơn (panel nhân viên). Portal khách thuê PHẢI
     *                                  truyền route riêng, xem giải thích ở
     *                                  InvoiceMomoService::createPaymentRequest().
     * @return array{qr_code: string, qr_image: string, checkout_url: string, amount: float, expired_at: string}
     */
    public static function createQr(Invoice $invoice, ?string $returnUrl = null): array
    {
        $amount = $invoice->remainingAmount();

        if ($amount <= 0) {
            throw new \RuntimeException('Hoá đơn đã thanh toán đủ, không cần tạo mã QR.');
        }

        // KHÔNG dùng $invoice->contract?->room?->building (quan hệ mặc định) — nếu hợp đồng đã bị
        // xoá mềm, SoftDeletes global scope làm $building = null, khiến isConfiguredFor() RƠI NHẦM
        // về tài khoản PayOS CHUNG của hệ thống (nếu tài khoản chung đã cấu hình) thay vì đúng tài
        // khoản PayOS RIÊNG của chủ toà — tiền chuyển SAI tài khoản, không chỉ đơn thuần thiếu QR
        // (cùng lỗi lớp SoftDeletes đã gặp và sửa ở InvoiceContentRenderer/InvoicePrintController).
        $building = $invoice->contract_id
            ? Contract::withoutGlobalScopes()
                ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($invoice->contract_id)
                ?->room?->building
            : null;

        if (! self::isConfiguredFor($building)) {
            throw new \RuntimeException('Chưa cấu hình PayOS (Client ID/API Key/Checksum Key) — vào phần Cấu hình thanh toán hoặc khai báo tài khoản PayOS riêng ở Toà nhà để thêm.');
        }

        [$clientId, $apiKey, $checksumKey] = self::resolveCredentials($building);

        $payOS = new PayOS($clientId, $apiKey, $checksumKey);

        // Huỷ link cũ (nếu còn) trước khi tạo link mới cho đúng hoá đơn này — best-effort, PayOS
        // cũng tự hết hạn link không dùng nên lỗi ở đây không chặn tạo link mới.
        if ($invoice->payos_order_code) {
            try {
                $payOS->cancelPaymentLink($invoice->payos_order_code);
            } catch (\Throwable $e) {
                Log::info('InvoicePayOsService: huỷ link PayOS cũ thất bại, bỏ qua', [
                    'invoice_id' => $invoice->id,
                    'old_order_code' => $invoice->payos_order_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $orderCode = self::generateUniqueOrderCode();
        $expiredAt = now()->addMinutes(self::EXPIRE_MINUTES);

        $response = $payOS->createPaymentLink([
            'orderCode'   => $orderCode,
            'amount'      => (int) round($amount),
            'description' => substr('TT HD ' . $invoice->id, 0, 25),
            'returnUrl'   => $returnUrl ?? url('/minihouse-admin/invoices/' . $invoice->id . '/edit'),
            'cancelUrl'   => $returnUrl ?? url('/minihouse-admin/invoices/' . $invoice->id . '/edit'),
            'expiredAt'   => $expiredAt->timestamp,
        ]);

        $invoice->update([
            'payos_order_code'   => $orderCode,
            'payos_checkout_url' => $response['checkoutUrl'] ?? null,
            'payos_qr_code'      => $response['qrCode'] ?? null,
            'payos_expired_at'   => $expiredAt,
        ]);

        return [
            'qr_code'      => $response['qrCode'] ?? '',
            'qr_image'     => filled($response['qrCode'] ?? null) ? QrCodeGenerator::dataUri($response['qrCode'], 260) : '',
            'checkout_url' => $response['checkoutUrl'] ?? '',
            'amount'       => $amount,
            'expired_at'   => $expiredAt->toIso8601String(),
        ];
    }

    // 9 chữ số, LUÔN bắt đầu bằng số 9 — Order bên Home dùng mã 6-8 chữ số (xem Order::booted()),
    // dải số này không bao giờ đụng nhau dù dùng CHUNG 1 tài khoản PayOS (PayOS yêu cầu orderCode
    // không trùng trong TOÀN BỘ tài khoản, không phải trong từng bảng riêng của app). Toà nhà dùng
    // tài khoản PayOS RIÊNG vẫn giữ đúng dải số này — orderCode chỉ cần duy nhất TRONG TỪNG tài
    // khoản PayOS, dải số 9xxxxxxxx của app tự nhiên không trùng nhau giữa các tài khoản khác nhau.
    private static function generateUniqueOrderCode(): int
    {
        do {
            $code = (int) ('9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT));
        } while (Invoice::withoutGlobalScopes()->where('payos_order_code', $code)->exists());

        return $code;
    }
}
