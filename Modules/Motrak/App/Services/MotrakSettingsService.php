<?php

namespace Modules\Motrak\App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Motrak\App\Exceptions\MotrakApiException;
use Modules\Motrak\App\Settings\MotrakSettings;

/**
 * Quản lý cấu hình Motrak: Token (mã hoá bằng Spatie Settings, không bao giờ trả ra ngoài), bật/tắt, chu kỳ đồng bộ.
 *
 * Token KHÔNG xuất hiện trong log/exception/response: mọi nơi khác chỉ biết trạng thái ('none' | 'saved' | 'unreadable') và 4 ký tự cuối (token_hint).
 * Token đã lưu mà không giải mã được (VD APP_KEY của máy chủ khác lúc lưu) → 'unreadable': CMS báo "cần nhập lại" thay vì lỗi.
 */
class MotrakSettingsService
{
    public const TOKEN_NONE = 'none';
    public const TOKEN_SAVED = 'saved';
    public const TOKEN_UNREADABLE = 'unreadable';

    /** @throws \Throwable khi Token đã lưu không giải mã được */
    private function load(): MotrakSettings
    {
        return app(MotrakSettings::class);
    }

    /** Làm mới bản cài đặt đang giữ trong bộ nhớ (sau khi sửa trực tiếp DB / giữa các request test). */
    public function refresh(): void
    {
        app()->forgetInstance(MotrakSettings::class);
    }

    public function tokenState(): string
    {
        try {
            return filled($this->load()->token) ? self::TOKEN_SAVED : self::TOKEN_NONE;
        } catch (\Throwable) {
            return self::TOKEN_UNREADABLE;
        }
    }

    public function isEnabled(): bool
    {
        try {
            return $this->load()->enabled;
        } catch (\Throwable) {
            return false;
        }
    }

    public function intervalMinutes(): int
    {
        try {
            $v = $this->load()->sync_interval_minutes;
        } catch (\Throwable) {
            $v = null;
        }

        return in_array($v, (array) config('motrak.sync.allowed_intervals'), true) ? (int) $v : (int) config('motrak.sync.default_interval_minutes', 1);
    }

    /** @return array{state: string, hint: ?string, updated_at: ?string} thông tin Token an toàn để hiển thị (không có Token). */
    public function tokenInfo(): array
    {
        try {
            $s = $this->load();

            return ['state' => filled($s->token) ? self::TOKEN_SAVED : self::TOKEN_NONE, 'hint' => $s->token_hint, 'updated_at' => $s->token_updated_at];
        } catch (\Throwable) {
            return ['state' => self::TOKEN_UNREADABLE, 'hint' => null, 'updated_at' => null];
        }
    }

    /**
     * Lấy Token để gọi API. CHỈ dùng nội bộ trong MotrakApiService.
     *
     * @throws MotrakApiException not_configured | token_unreadable
     */
    public function token(): string
    {
        try {
            $token = $this->load()->token;
        } catch (\Throwable) {
            throw new MotrakApiException(MotrakApiException::TOKEN_UNREADABLE, 'Token đã lưu không đọc được (có thể do khoá mã hoá của máy chủ đã đổi). Vui lòng nhập lại Token.');
        }

        if (! filled($token)) {
            throw new MotrakApiException(MotrakApiException::NOT_CONFIGURED, 'Chưa cấu hình Token Motrak.');
        }

        return (string) $token;
    }

    /**
     * Lưu (hoặc thay) Token. Bỏ khoảng trắng đầu/cuối và tiền tố "Bearer " nếu người dùng dán nhầm.
     *
     * @throws InvalidArgumentException khi Token không hợp lệ về hình thức
     */
    public function saveToken(string $token): void
    {
        $token = trim(preg_replace('/^\s*Bearer\s+/i', '', trim($token)));

        if (strlen($token) < 8 || strlen($token) > 4096 || preg_match('/\s/', $token)) {
            throw new InvalidArgumentException('Token không hợp lệ (tối thiểu 8 ký tự, không chứa khoảng trắng).');
        }

        $this->recoverUnreadableToken();

        $s = $this->load();
        $s->token = $token;
        $s->token_hint = substr($token, -4);
        $s->token_updated_at = now()->toIso8601String();
        $s->save();
    }

    /** Xoá Token và tắt đồng bộ tự động. Dữ liệu GPS đã đồng bộ giữ nguyên. */
    public function clearToken(): void
    {
        $this->recoverUnreadableToken();

        $s = $this->load();
        $s->token = null;
        $s->token_hint = null;
        $s->token_updated_at = now()->toIso8601String();
        $s->enabled = false;
        $s->save();
    }

    /** @throws InvalidArgumentException chu kỳ không nằm trong danh sách cho phép */
    public function update(bool $enabled, int $intervalMinutes): void
    {
        if (! in_array($intervalMinutes, (array) config('motrak.sync.allowed_intervals'), true)) {
            throw new InvalidArgumentException('Chu kỳ đồng bộ phải là một trong: ' . implode(', ', (array) config('motrak.sync.allowed_intervals')) . ' phút.');
        }

        $this->recoverUnreadableToken();

        $s = $this->load();
        $s->enabled = $enabled;
        $s->sync_interval_minutes = $intervalMinutes;
        $s->save();
    }

    /** Token cũ không giải mã được thì đặt lại về rỗng để lưu/sửa tiếp được. */
    private function recoverUnreadableToken(): void
    {
        try {
            // Spatie nạp cài đặt LƯỜI (khi đọc thuộc tính) nên phải đọc token mới phát hiện được payload hỏng.
            $this->load()->token;
        } catch (\Throwable) {
            DB::table('settings')->where('group', 'motrak')->where('name', 'token')->update(['payload' => 'null']);
            DB::table('settings')->where('group', 'motrak')->where('name', 'token_hint')->update(['payload' => 'null']);
            $this->refresh();
        }
    }
}
