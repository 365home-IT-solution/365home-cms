<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public const GATEWAY_FRIGATE = 'frigate';

    public const GATEWAY_GO2RTC = 'go2rtc';

    public const GATEWAY_CLOUD = 'cloud';

    public const GATEWAYS = [self::GATEWAY_FRIGATE, self::GATEWAY_GO2RTC, self::GATEWAY_CLOUD];

    protected $fillable = ['partner_id', 'base_url', 'go2rtc_url', 'api_key', 'username', 'password', 'gateway_type', 'provider_credentials'];

    protected $casts = [
        'api_key' => 'encrypted',
        'username' => 'encrypted',
        'password' => 'encrypted',
        // Tài khoản developer của hãng camera cloud, theo từng hãng: {"imou": {...}, "ezviz": {...}}.
        'provider_credentials' => 'encrypted:array',
    ];

    // Trả về dòng đã lưu nếu có, hoặc 1 instance CHƯA LƯU (partner_id gán sẵn) nếu đối tác này chưa
    // từng cấu hình — isConfigured()/hasFrigateCredentials() tự trả false trên instance rỗng đó,
    // KHÔNG throw, để mọi nơi gọi (FrigateApiClient, Camera::wsProxyUrl()...) xử lý "chưa cấu hình"
    // như 1 trạng thái bình thường thay vì phải tự kiểm tra null trước.
    public static function forPartner(?string $partnerId): self
    {
        if (blank($partnerId)) {
            return new self;
        }

        return static::query()->find($partnerId) ?? new self(['partner_id' => $partnerId]);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    // Cách đối tác này lấy luồng camera: frigate (mặc định, đủ live + lịch sử), go2rtc trần (chỉ live +
    // ảnh) hoặc cloud (camera chỉ có trên đám mây của hãng, mỗi camera chọn hãng riêng).
    public function gatewayType(): string
    {
        return in_array($this->gateway_type, self::GATEWAYS, true) ? (string) $this->gateway_type : self::GATEWAY_FRIGATE;
    }

    public function isConfigured(): bool
    {
        return match ($this->gatewayType()) {
            self::GATEWAY_GO2RTC => $this->isGo2RtcConfigured(),
            self::GATEWAY_CLOUD => ! empty($this->provider_credentials),
            default => filled($this->base_url),
        };
    }

    /** @return array<string, mixed> */
    public function providerCredentials(string $provider): array
    {
        $all = is_array($this->provider_credentials) ? $this->provider_credentials : [];

        return is_array($all[$provider] ?? null) ? $all[$provider] : [];
    }

    /**
     * Gộp thông tin đăng nhập của 1 hãng: trường bí mật để trống/không gửi = giữ giá trị cũ, $values
     * = null = xoá toàn bộ cấu hình hãng đó.
     *
     * @param  array<string, mixed>|null  $values
     * @param  list<string>  $secretKeys
     */
    public function mergeProviderCredentials(string $provider, ?array $values, array $secretKeys): void
    {
        $all = is_array($this->provider_credentials) ? $this->provider_credentials : [];

        if ($values === null) {
            unset($all[$provider]);
        } else {
            $current = is_array($all[$provider] ?? null) ? $all[$provider] : [];

            foreach ($values as $key => $value) {
                if (in_array($key, $secretKeys, true) && blank($value)) {
                    continue;
                }

                $current[$key] = $value;
            }

            $all[$provider] = $current;
        }

        $this->provider_credentials = $all;
    }

    public function isGo2RtcConfigured(): bool
    {
        return filled($this->go2rtcBaseUrl());
    }

    public function go2rtcBaseUrl(): ?string
    {
        if (filled($this->go2rtc_url)) {
            return (string) $this->go2rtc_url;
        }

        // Backward compatibility for records that previously stored a direct :1984 URL in base_url.
        return parse_url((string) $this->base_url, PHP_URL_PORT) === 1984
            ? (string) $this->base_url
            : null;
    }

    public function hasFrigateCredentials(): bool
    {
        return filled($this->username) && filled($this->password);
    }
}
