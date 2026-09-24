<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Cấu hình server go2rtc/Frigate RIÊNG của TỪNG đối tác (bảng phụ 1-1 với partners, khoá chính =
// partner_id) — thay cho App\Settings\CameraSettings cũ (Spatie Settings, 1 dòng DUY NHẤT dùng
// CHUNG cho mọi đối tác). Mirror đúng pattern Modules\Minihouse\App\Models\BuildingSetting/ZaloSetting
// (get-or-new theo khoá ngoài, không throw khi chưa có dòng nào — coi như "chưa cấu hình").
class CameraSetting extends Model
{
    protected $table = 'camera_settings';

    protected $primaryKey = 'partner_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['partner_id', 'base_url', 'api_key', 'username', 'password'];

    protected $casts = [
        'api_key'  => 'encrypted',
        'username' => 'encrypted',
        'password' => 'encrypted',
    ];

    // Trả về dòng đã lưu nếu có, hoặc 1 instance CHƯA LƯU (partner_id gán sẵn) nếu đối tác này chưa
    // từng cấu hình — isConfigured()/hasFrigateCredentials() tự trả false trên instance rỗng đó,
    // KHÔNG throw, để mọi nơi gọi (FrigateApiClient, Camera::wsProxyUrl()...) xử lý "chưa cấu hình"
    // như 1 trạng thái bình thường thay vì phải tự kiểm tra null trước.
    public static function forPartner(?string $partnerId): self
    {
        if (blank($partnerId)) {
            return new self();
        }

        return static::query()->find($partnerId) ?? new self(['partner_id' => $partnerId]);
    }

    public function partner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function isConfigured(): bool
    {
        return filled($this->base_url);
    }

    public function hasFrigateCredentials(): bool
    {
        return filled($this->username) && filled($this->password);
    }
}
