<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình MIỄN PHÍ THÁNG ĐẦU của ký quỹ (menu "Cấu hình web" > "Ký quỹ"). Công tắc tắt (mặc định) = mọi đối tác mới ký quỹ và tính hoa hồng ngay như bình thường.
// Bật = đối tác MỚI ở ngoài các tỉnh bắt buộc ký quỹ ngay được miễn hoa hồng và miễn nạp ký quỹ trong thời gian miễn phí (xem App\Services\PartnerTrialService).
// Đổi chính sách chỉ cần đổi ở đây, không phải sửa code; hợp đồng đã tạo trước đó giữ đúng nội dung đã ghi.
class EscrowSettings extends Settings
{
    public bool $free_trial_enabled;

    public int $free_trial_months;

    /** @var array<int, int> Mã tỉnh/thành (provinces.code) phải ký quỹ và tính hoa hồng ngay từ đầu, không được miễn. */
    public array $immediate_province_codes;

    /** @var array<int, int> Nhắc đối tác nạp ký quỹ trước khi hết miễn phí bao nhiêu ngày. */
    public array $reminder_days;

    public static function group(): string
    {
        return 'escrow';
    }
}
