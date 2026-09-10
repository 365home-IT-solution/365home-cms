<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;

// Cấu hình Zalo OA/ZNS RIÊNG của MiniHouse — luôn CHỈ 1 DÒNG duy nhất (id=1), dùng ZaloSetting::
// current() để lấy/tạo, giống pattern PaymentConfiguration của Home nhưng bảng riêng, không chung.
class ZaloSetting extends Model
{
    protected $table = 'minihouse_zalo_settings';

    protected $fillable = [
        'app_id', 'app_secret', 'access_token', 'refresh_token', 'access_token_expires_at',
        'template_payment_reminder', 'template_contract_expiry', 'template_maintenance', 'template_otp',
    ];

    // Mã hoá tại chỗ giống ZaloSettings bên Home (App\Settings\ZaloSettings::encrypted()) — app_secret
    // và 2 token đều là bí mật dùng để gọi API Zalo thay mặt cả hệ thống, không lưu dạng thô trong DB.
    protected $casts = [
        'app_secret'              => 'encrypted',
        'access_token'            => 'encrypted',
        'refresh_token'           => 'encrypted',
        'access_token_expires_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    public function isConfigured(): bool
    {
        return filled($this->app_id) && filled($this->app_secret) && filled($this->refresh_token);
    }

    public function templateFor(string $reminderType): ?string
    {
        return match ($reminderType) {
            Reminder::TYPE_PAYMENT     => $this->template_payment_reminder,
            Reminder::TYPE_CONTRACT    => $this->template_contract_expiry,
            Reminder::TYPE_MAINTENANCE => $this->template_maintenance,
            default                    => null,
        };
    }
}
