<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Models\Building;

// Dựng ảnh QR chuyển khoản VietQR cho ĐÚNG tài khoản ngân hàng của chủ toà nhà (mỗi toà 1 chủ, thu
// tiền riêng — khác với PayOS dùng chung 1 tài khoản cho toàn hệ thống). Dùng API ảnh miễn phí công
// khai của VietQR (img.vietqr.io) — không cần đăng ký API key, không tự dựng chuỗi EMVCo/tính CRC16
// tay. Chỉ dùng để HIỂN THỊ (không xác nhận thanh toán tự động như PayOS) — nhân viên vẫn tự ghi
// nhận thanh toán thủ công như bình thường sau khi khách chuyển khoản.
class VietQrService
{
    // 'compact2' — mẫu gọn có sẵn tên/số tiền/nội dung trên ảnh, phù hợp in phiếu thu giấy khổ nhỏ.
    private const TEMPLATE = 'compact2';

    public static function imageUrl(Building $building, float $amount, string $addInfo): ?string
    {
        if (! $building->hasOwnerBankInfo()) {
            return null;
        }

        $query = http_build_query([
            'amount'      => max(0, (int) round($amount)),
            'addInfo'     => $addInfo,
            'accountName' => static::asciiUpper($building->owner_bank_account_holder),
        ]);

        return sprintf(
            'https://img.vietqr.io/image/%s-%s-%s.png?%s',
            $building->owner_bank_bin,
            rawurlencode((string) $building->owner_bank_account_number),
            self::TEMPLATE,
            $query,
        );
    }

    // Chuyển ảnh QR (URL trên) thành data URI base64 — BẮT BUỘC khi xuất PDF qua Dompdf vì
    // ContractPdfRenderer (dùng chung cho cả hợp đồng lẫn hoá đơn) tắt isRemoteEnabled (an toàn,
    // không cho PDF tự tải ảnh từ mạng ngoài) nên <img src="https://..."> sẽ KHÔNG hiện được trong
    // file PDF nếu không tự tải trước rồi nhúng thẳng base64 vào HTML. Xem trang In (route thường,
    // xem trực tiếp trên trình duyệt) thì KHÔNG cần bước này — trình duyệt tự tải ảnh bình thường.
    public static function toDataUri(string $imageUrl): ?string
    {
        try {
            $response = Http::timeout(8)->get($imageUrl);

            if (! $response->successful()) {
                return null;
            }

            return 'data:image/png;base64,' . base64_encode($response->body());
        } catch (\Throwable $e) {
            Log::warning('VietQrService: không tải được ảnh QR để nhúng PDF', [
                'url'   => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // Tên chủ tài khoản trên QR PHẢI viết không dấu, in hoa — đúng chuẩn hiển thị của các app ngân
    // hàng (Str::ascii loại bỏ dấu tiếng Việt).
    private static function asciiUpper(?string $value): string
    {
        return Str::of($value ?? '')->ascii()->upper()->toString();
    }
}
