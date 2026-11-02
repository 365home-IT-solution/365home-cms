<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Partner;
use Illuminate\Validation\ValidationException;

/**
 * Hình thức thanh toán khi đặt phòng mà đối tác chấp nhận (partners.booking_payment_mode):
 *   both        — khách chọn tiền mặt tại quầy (cod) hoặc thanh toán online (PayOS / SePay / Pay2S) — như trước đây
 *   cash_only   — chỉ cod: không tạo link/QR thanh toán, đơn chờ nhân viên xác nhận thu tại quầy
 *   online_only — chỉ PayOS (tên chung của cổng online; cổng cụ thể do đối tác chọn ở kênh thanh toán)
 *
 * Mọi nơi tạo đơn (API khách, khách vãng lai, API admin, Filament) gọi resolve() để dùng chung 1 quy tắc. Đối tác không xác định
 * (đơn không gắn chi nhánh của đối tác nào) coi như "both".
 */
class BookingPaymentPolicy
{
    public const CASH = 'cod';

    public const ONLINE = 'PayOS';

    public static function modeForCategory(?int $categoryId): string
    {
        return self::modeForPartner(PayOsAccountResolver::partnerIdForCategory($categoryId));
    }

    public static function modeForPartner(?string $partnerId): string
    {
        if (! $partnerId) {
            return Partner::PAYMENT_MODE_BOTH;
        }

        $mode = (string) Partner::withTrashed()->whereKey($partnerId)->value('booking_payment_mode');

        return array_key_exists($mode, Partner::PAYMENT_MODES) ? $mode : Partner::PAYMENT_MODE_BOTH;
    }

    /** @return string[] phương thức được phép, phương thức mặc định đứng đầu. */
    public static function methodsForMode(string $mode): array
    {
        return match ($mode) {
            Partner::PAYMENT_MODE_CASH_ONLY => [self::CASH],
            Partner::PAYMENT_MODE_ONLINE_ONLY => [self::ONLINE],
            default => [self::ONLINE, self::CASH],
        };
    }

    /** @return string[] */
    public static function methodsForCategory(?int $categoryId): array
    {
        return self::methodsForMode(self::modeForCategory($categoryId));
    }

    public static function allowsOnline(?int $categoryId): bool
    {
        return in_array(self::ONLINE, self::methodsForCategory($categoryId), true);
    }

    /**
     * Chọn phương thức cho đơn mới: không gửi → $preferred nếu đối tác nhận, không thì phương thức duy nhất đối tác nhận; gửi mà đối tác
     * không nhận → 422 (khoá payment_method). API khách mặc định online; API admin (nhân viên tạo đơn tại quầy) mặc định tiền mặt.
     *
     * @throws ValidationException
     */
    public static function resolve(?int $categoryId, ?string $requested, string $preferred = self::ONLINE): string
    {
        $mode = self::modeForCategory($categoryId);
        $allowed = self::methodsForMode($mode);

        if ($requested === null || $requested === '') {
            return in_array($preferred, $allowed, true) ? $preferred : $allowed[0];
        }

        if (! in_array($requested, $allowed, true)) {
            throw ValidationException::withMessages(['payment_method' => [self::rejectMessage($mode)]]);
        }

        return $requested;
    }

    public static function rejectMessage(string $mode): string
    {
        return $mode === Partner::PAYMENT_MODE_CASH_ONLY
            ? 'Chỗ nghỉ này chỉ nhận thanh toán tiền mặt tại quầy.'
            : 'Chỗ nghỉ này chỉ nhận thanh toán online.';
    }

    /** Khối `payment` trả kèm chi tiết/preview phòng cho app: app chỉ hiện các lựa chọn trong `methods`. @return array{mode: string, methods: string[], default: string, cash: bool, online: bool} */
    public static function describe(?int $categoryId): array
    {
        $mode = self::modeForCategory($categoryId);
        $methods = self::methodsForMode($mode);

        return [
            'mode' => $mode,
            'methods' => $methods,
            'default' => $methods[0],
            'cash' => in_array(self::CASH, $methods, true),
            'online' => in_array(self::ONLINE, $methods, true),
        ];
    }
}
