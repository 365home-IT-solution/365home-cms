<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Chuỗi QR chuyển khoản nhanh NAPAS 247 / VietQR (EMV QR Code, dạng động) cho 1 tài khoản ngân hàng, số tiền và nội dung cố định.
 * Cùng dạng chuỗi PayOS trả ở `qrCode`, nên app/web đang vẽ QR từ chuỗi đó dùng được ngay khi chuyển sang Pay2S (không cần gọi API ngoài).
 */
class VietQrPayload
{
    public static function build(string $bankBin, string $accountNumber, int $amount, string $content): string
    {
        $merchant = self::field('00', 'A000000727')
            . self::field('01', self::field('00', $bankBin) . self::field('01', $accountNumber))
            . self::field('02', 'QRIBFTTA');

        $payload = self::field('00', '01')
            . self::field('01', '12')
            . self::field('38', $merchant)
            . self::field('53', '704')
            . ($amount > 0 ? self::field('54', (string) $amount) : '')
            . self::field('58', 'VN')
            . self::field('62', self::field('08', self::cleanContent($content)))
            . '6304';

        return $payload . self::crc16($payload);
    }

    /** Nội dung chuyển khoản trong QR: không dấu, tối đa 50 ký tự (giới hạn của chuẩn). */
    public static function cleanContent(string $content): string
    {
        return Str::limit(trim((string) Str::of($content)->ascii()->replaceMatches('/[^A-Za-z0-9 \-_.]/', '')), 50, '');
    }

    private static function field(string $id, string $value): string
    {
        return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    /** CRC-16/CCITT-FALSE (đa thức 0x1021, khởi tạo 0xFFFF) — chuẩn EMV QR. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        foreach (str_split($data) as $char) {
            $crc ^= ord($char) << 8;

            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
