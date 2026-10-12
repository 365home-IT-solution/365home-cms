<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\EscrowSettings;

// Các mức do chủ nền tảng chỉnh được mà hợp đồng mẫu in ra. Khoản tiền đảm bảo (Điều 4.2): đặt ở Cấu hình web › Ký quỹ (mặc định 1.000.000đ);
// chưa có cấu hình thì dùng config('contract.guarantee_vnd'). Hợp đồng đã tạo giữ đúng số đã in; hợp đồng tạo mới và mức ký quỹ khi hợp đồng có hiệu lực dùng số hiện hành.
final class ContractTerms
{
    public const DEFAULT_GUARANTEE_VND = 1_000_000;

    public static function guaranteeVnd(): int
    {
        try {
            $value = (int) (app(EscrowSettings::class)->guarantee_vnd ?? 0);
        } catch (\Throwable $e) {
            $value = 0; // chưa chạy migration cấu hình
        }

        return $value > 0 ? $value : max(1, (int) config('contract.guarantee_vnd', self::DEFAULT_GUARANTEE_VND));
    }
}
