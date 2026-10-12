<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

// Tiện ích cho chữ ký do công cụ bên ngoài tạo (USB token, CKS...).
class ContractSignatureFields
{
    /**
     * Thời gian ký lấy từ chính chữ ký (để hợp đồng ghi đúng giờ ký thật như hình chữ ký trong PDF, thay vì giờ tải lên). Chỉ tin khi hợp lý: không ở tương lai
     * quá 5 phút và không cũ hơn 7 ngày; ngoài ra dùng $fallback.
     */
    public static function trustedSigningTime(?string $iso, \Illuminate\Support\Carbon $fallback): \Illuminate\Support\Carbon
    {
        if (blank($iso)) {
            return $fallback;
        }

        try {
            $time = \Illuminate\Support\Carbon::parse($iso)->setTimezone($fallback->getTimezone());
        } catch (\Throwable) {
            return $fallback;
        }

        return $time->lte($fallback->copy()->addMinutes(5)) && $time->gte($fallback->copy()->subDays(7)) ? $time : $fallback;
    }
}
