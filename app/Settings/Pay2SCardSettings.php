<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Thanh toán THẺ (Visa / Mastercard / JCB / AMEX / UnionPay) qua Pay2S "Thanh toán thẻ popup" — CHỈ cho chi nhánh của 365home (category_ids), không dùng cho đối tác.
// Tiền thẻ về tài khoản Pay2S của 365home. Khoá lấy ở tài khoản Pay2S → Thẻ & Ví điện tử → Kết nối; chỉnh ở CMS (Cấu hình web → Pay2S — thanh toán thẻ).
class Pay2SCardSettings extends Settings
{
    public bool $enabled;

    public ?string $partner_code;

    public ?string $access_key;

    public ?string $secret_key;

    // Kết nối mặc định (connectionId) — để trống = Pay2S dùng kết nối mặc định của tài khoản.
    public ?string $connection_id;

    // true = chế độ thử: phiên chỉ chạy ở sandbox (thẻ giả lập). Mặc định bật để không lỡ thu tiền thật khi chưa kiểm thử.
    public bool $sandbox_only;

    // Origin HTTPS đã được Pay2S duyệt cho cửa hàng (vd https://365home.vn). Để trống = theo APP_URL.
    public ?string $merchant_origin;

    public ?string $api_url;

    /** @var array<int, int> chi nhánh (categories.id, gồm cả khu vực con) được thanh toán thẻ */
    public array $category_ids;

    public static function group(): string
    {
        return 'pay2s_card';
    }

    public static function encrypted(): array
    {
        return ['access_key', 'secret_key'];
    }
}
