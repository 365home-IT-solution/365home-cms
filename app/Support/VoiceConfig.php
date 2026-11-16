<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\VoiceSettings;
use Illuminate\Validation\ValidationException;

/**
 * Cấu hình TURN / STUN cho gọi thoại: giá trị chỉnh ở CMS (App\Settings\VoiceSettings) ưu tiên, để trống ⇒ .env (config/services.php → turn).
 * Dùng chung cho App\Services\GameVoiceService, Filament "Mini game — Chat" và /api/admin/games/voice-settings.
 */
final class VoiceConfig
{
    public const SOURCE_CMS  = 'cms';
    public const SOURCE_ENV  = 'env';
    public const SOURCE_NONE = 'none';

    /** turn:host:port?transport=udp|tcp · turns:host:port?transport=tcp · stun:host:port */
    private const URL_PATTERN = '/^(stun|stuns|turn|turns):[A-Za-z0-9.\-]+(:\d{1,5})?(\?transport=(udp|tcp))?$/';

    private static function settings(): ?VoiceSettings
    {
        try {
            return app(VoiceSettings::class);
        } catch (\Throwable $e) {
            // Chưa chạy migration settings / không giải mã được khoá (APP_KEY khác) ⇒ coi như chưa chỉnh ở CMS
            return null;
        }
    }

    /** @return array{0: ?string, 1: string} [giá trị, nguồn] */
    private static function pick(string $field, string $configKey): array
    {
        $cms = trim((string) (self::settings()?->{$field} ?? ''));
        if ($cms !== '') {
            return [$cms, self::SOURCE_CMS];
        }
        $env = trim((string) config("services.turn.{$configKey}"));

        return $env !== '' ? [$env, self::SOURCE_ENV] : [null, self::SOURCE_NONE];
    }

    /** @return string[] */
    public static function turnUrls(): array
    {
        return self::split(self::pick('turn_urls', 'urls')[0]);
    }

    /** @return string[] */
    public static function stunUrls(): array
    {
        return self::split(self::pick('stun_urls', 'stun_urls')[0]);
    }

    public static function turnSecret(): string
    {
        return (string) self::pick('turn_secret', 'secret')[0];
    }

    public static function turnTtl(): int
    {
        $ttl = self::settings()?->turn_ttl ?: (int) config('services.turn.ttl', 21600);

        return max(300, (int) $ttl);
    }

    /** @return array{turn_urls: string, stun_urls: string, turn_secret: string, turn_ttl: string} nguồn đang dùng của từng mục */
    public static function sources(): array
    {
        return [
            'turn_urls'   => self::pick('turn_urls', 'urls')[1],
            'stun_urls'   => self::pick('stun_urls', 'stun_urls')[1],
            'turn_secret' => self::pick('turn_secret', 'secret')[1],
            'turn_ttl'    => self::settings()?->turn_ttl ? self::SOURCE_CMS : self::SOURCE_ENV,
        ];
    }

    /** Payload cho màn quản trị — KHÔNG bao giờ trả khoá bí mật. */
    public static function adminPayload(): array
    {
        $s = self::settings();

        return [
            // Giá trị đã chỉnh ở CMS (null = đang theo .env)
            'turn_urls'       => $s?->turn_urls ? self::split($s->turn_urls) : null,
            'stun_urls'       => $s?->stun_urls ? self::split($s->stun_urls) : null,
            'turn_secret_set' => trim((string) ($s?->turn_secret ?? '')) !== '',
            'turn_ttl'        => $s?->turn_ttl,
            // Giá trị đang thực sự dùng (CMS ưu tiên, trống ⇒ .env)
            'effective' => [
                'turn_urls'        => self::turnUrls(),
                'stun_urls'        => self::stunUrls(),
                'turn_secret_set'  => self::turnSecret() !== '',
                'turn_ttl'         => self::turnTtl(),
                'turn_configured'  => self::turnUrls() !== [] && self::turnSecret() !== '',
            ],
            'sources' => self::sources(),
        ];
    }

    /**
     * Lưu cấu hình từ CMS / API admin. Gửi trường nào sửa trường đó.
     *  - turn_urls / stun_urls: mảng hoặc chuỗi (xuống dòng / dấu phẩy). Rỗng ⇒ bỏ cấu hình CMS, quay về .env.
     *  - turn_secret: chuỗi mới; không gửi / null ⇒ giữ nguyên. clear_turn_secret = true ⇒ xoá khoá đã lưu (quay về .env).
     *  - turn_ttl: giây (300–604800); null ⇒ theo .env.
     */
    public static function save(array $data, string $errorPrefix = ''): void
    {
        $s = self::settings();
        if (! $s) {
            throw ValidationException::withMessages([$errorPrefix . 'turn_urls' => 'Chưa khởi tạo cấu hình gọi thoại — chạy php artisan migrate.']);
        }

        foreach (['turn_urls', 'stun_urls'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $urls = self::split($data[$field]);
            foreach ($urls as $u) {
                if (! preg_match(self::URL_PATTERN, $u)) {
                    throw ValidationException::withMessages([$errorPrefix . $field => "Địa chỉ không hợp lệ: {$u}. Ví dụ: "
                        . ($field === 'turn_urls' ? 'turn:turn.365home.vn:3478?transport=udp' : 'stun:turn.365home.vn:3478')]);
                }
                if ($field === 'turn_urls' && ! preg_match('/^turns?:/', $u)) {
                    throw ValidationException::withMessages([$errorPrefix . $field => "Địa chỉ TURN phải bắt đầu bằng turn: hoặc turns: ({$u})."]);
                }
                if ($field === 'stun_urls' && ! preg_match('/^stuns?:/', $u)) {
                    throw ValidationException::withMessages([$errorPrefix . $field => "Địa chỉ STUN phải bắt đầu bằng stun: ({$u})."]);
                }
            }
            $s->{$field} = $urls === [] ? null : implode(',', array_values(array_unique($urls)));
        }

        if (! empty($data['clear_turn_secret'])) {
            $s->turn_secret = null;
        } elseif (isset($data['turn_secret']) && trim((string) $data['turn_secret']) !== '') {
            $secret = trim((string) $data['turn_secret']);
            if (mb_strlen($secret) < 16) {
                throw ValidationException::withMessages([$errorPrefix . 'turn_secret' => 'Khoá bí mật tối thiểu 16 ký tự (nên dùng chuỗi ngẫu nhiên 64 ký tự).']);
            }
            $s->turn_secret = $secret;
        }

        if (array_key_exists('turn_ttl', $data)) {
            $ttl = $data['turn_ttl'] === null || $data['turn_ttl'] === '' ? null : (int) $data['turn_ttl'];
            if ($ttl !== null && ($ttl < 300 || $ttl > 604800)) {
                throw ValidationException::withMessages([$errorPrefix . 'turn_ttl' => 'Thời hạn mật khẩu TURN từ 300 giây (5 phút) đến 604800 giây (7 ngày).']);
            }
            $s->turn_ttl = $ttl;
        }

        $s->save();
    }

    public static function randomSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @return string[] */
    private static function split(mixed $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $parts), fn (string $v) => $v !== ''));
    }
}
