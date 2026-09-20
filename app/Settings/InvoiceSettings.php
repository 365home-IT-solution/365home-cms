<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình kết nối MISA meInvoice — quản lý trong web (menu "Cấu hình web" > "Hoá đơn điện tử")
// thay vì .env, giống pattern CameraSettings. Ở giai đoạn sườn hiện tại, `enabled` LUÔN false cho
// tới khi có đủ AppID/tài khoản kỹ thuật thật từ MISA — xem Modules\Invoice\App\Services\MisaInvoiceClient
// để biết vì sao isConfigured()=false thì mọi hoá đơn chỉ dừng ở bản nháp, không gọi API thật.
class InvoiceSettings extends Settings
{
    public bool $enabled;

    public string $provider;

    // Base URL môi trường MISA — dùng testapi.meinvoice.vn/api/v3 khi thử nghiệm, chuyển sang
    // api.meinvoice.vn/api/v3 khi lên chính thức (xem doc.meinvoice.vn/api/).
    public ?string $base_url;

    // AppID do MISA cấp riêng cho hệ thống 365home-cms (KHÔNG phải tài khoản đăng nhập web MISA).
    public ?string $app_id;

    public ?string $tax_code;

    public ?string $username;

    public ?string $password;

    // Mẫu số / Ký hiệu hoá đơn đã đăng ký với MISA. Theo đúng field-level spec object EInvoice
    // (doc.meinvoice.vn/webapi/Description/Entity/EInvoice.html): InvTypeCode là "loại mẫu" (VD
    // "01GTKT" — hoá đơn GTGT thường), InvTemplateNo là mẫu số ĐẦY ĐỦ (VD "01GTKT0/001"), InvSeries
    // là ký hiệu (VD "AB/19E" hoặc theo Nghị định 70/2025 dạng mới "1C25TYY").
    public ?string $invoice_type_code;

    public ?string $invoice_template_code;

    public ?string $invoice_series;

    public float $default_vat_rate;

    // usb_token: ký qua SignService cục bộ (cần cắm token vào máy chạy service).
    // hsm: ký từ xa qua API HSM (VNPT-CA/Viettel-CA/FastCA...), phù hợp hạ tầng server/VPS.
    public ?string $signing_mode;

    public ?string $hsm_endpoint;

    public static function group(): string
    {
        return 'invoice';
    }

    public static function encrypted(): array
    {
        return ['app_id', 'tax_code', 'username', 'password', 'hsm_endpoint'];
    }

    public function isConfigured(): bool
    {
        return $this->enabled
            && filled($this->base_url)
            && filled($this->app_id)
            && filled($this->tax_code)
            && filled($this->username)
            && filled($this->password)
            && filled($this->invoice_type_code)
            && filled($this->invoice_template_code)
            && filled($this->invoice_series);
    }
}
