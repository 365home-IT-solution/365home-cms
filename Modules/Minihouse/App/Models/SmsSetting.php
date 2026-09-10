<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;

// Cấu hình SMS Brandname RIÊNG của MiniHouse (eSMS.vn) — luôn CHỈ 1 DÒNG duy nhất (id=1), dùng
// SmsSetting::current() để lấy/tạo, cùng mẫu ZaloSetting.
class SmsSetting extends Model
{
    protected $table = 'minihouse_sms_settings';

    protected $fillable = ['api_key', 'secret_key', 'brandname'];

    // Mã hoá tại chỗ — api_key/secret_key là bí mật dùng để gọi API eSMS thay mặt cả hệ thống.
    protected $casts = [
        'api_key'    => 'encrypted',
        'secret_key' => 'encrypted',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    public function isConfigured(): bool
    {
        return filled($this->api_key) && filled($this->secret_key) && filled($this->brandname);
    }
}
