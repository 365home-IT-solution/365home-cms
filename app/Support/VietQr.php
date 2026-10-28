<?php

declare(strict_types=1);

namespace App\Support;

use Modules\BladeThemeV1\Support\QrCodeGenerator;

// Nội dung mã VietQR (chuẩn EMVCo / NAPAS "QR chuyển khoản nhanh 24/7") — sinh tại server thay vì lấy ảnh từ img.vietqr.io, để số tài
// khoản, số tiền, nội dung chuyển khoản (và URL trang thanh toán qua header Referer) không đi qua dịch vụ bên ngoài.
class VietQr
{
    public static function payload(string $bankBin, string $accountNumber, int $amount, string $content): string
    {
        $merchant = self::field('00', 'A000000727')
            . self::field('01', self::field('00', $bankBin) . self::field('01', $accountNumber))
            . self::field('02', 'QRIBFTTA');

        $body = self::field('00', '01')
            . self::field('01', '12')
            . self::field('38', $merchant)
            . self::field('53', '704')
            . self::field('54', (string) $amount)
            . self::field('58', 'VN')
            . self::field('62', self::field('08', $content))
            . '6304';

        return $body . self::crc16($body);
    }

    /** Ảnh QR dạng data URI (SVG) để nhúng thẳng vào trang. */
    public static function imageDataUri(string $payload, int $size = 300): string
    {
        return QrCodeGenerator::dataUri($payload, $size, 2);
    }

    // CRC-16/CCITT-FALSE (poly 0x1021, khởi tạo 0xFFFF) — trường 63 của EMVCo, 4 ký tự hex in hoa.
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        foreach (str_split($data) as $char) {
            $crc ^= ord($char) << 8;

            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private static function field(string $id, string $value): string
    {
        return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }
}
