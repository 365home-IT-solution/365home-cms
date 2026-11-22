<?php

namespace Modules\Motrak\App\Services;

use Carbon\Carbon;
use Modules\Motrak\App\Exceptions\MotrakApiException;
use Modules\Motrak\App\Models\MotrakSyncLog;

/**
 * Trạng thái kết nối Motrak hiện tại — SUY RA từ Token đã lưu + bản ghi nhật ký gần nhất (không lưu riêng, không ghi settings mỗi phút).
 *
 * Các trạng thái: not_configured (Chưa cấu hình), unchecked (chưa kiểm tra), connected (Đã kết nối), invalid_token (Token không hợp lệ),
 * no_response (API không phản hồi), connection_error (Lỗi kết nối), data_error (Lỗi dữ liệu), token_unreadable (Cần nhập lại Token).
 */
class MotrakStatusService
{
    public const LABELS = [
        'not_configured'   => 'Chưa cấu hình',
        'unchecked'        => 'Chưa kiểm tra',
        'connected'        => 'Đã kết nối',
        'invalid_token'    => 'Token không hợp lệ',
        'no_response'      => 'API không phản hồi',
        'connection_error' => 'Lỗi kết nối',
        'data_error'       => 'Lỗi dữ liệu',
        'token_unreadable' => 'Cần nhập lại Token',
        'invalid_credentials' => 'Sai tên đăng nhập / mật khẩu',
    ];

    public function __construct(private MotrakSettingsService $settings)
    {
    }

    /**
     * @return array{code: string, label: string, color: string, http_status: ?int, message: ?string, checked_at: ?string, vehicle_count: ?int, last_success_at: ?string}
     */
    public function status(): array
    {
        $lastSuccess = MotrakSyncLog::query()->whereIn('status', [MotrakSyncLog::STATUS_SUCCESS, MotrakSyncLog::STATUS_PARTIAL])->latest('started_at')->first();
        $base = ['http_status' => null, 'message' => null, 'checked_at' => null, 'vehicle_count' => $lastSuccess?->total_vehicles, 'last_success_at' => $lastSuccess?->started_at?->toIso8601String()];

        $info = $this->settings->tokenInfo();
        if ($info['state'] === MotrakSettingsService::TOKEN_NONE && ! $this->settings->hasCredentials()) {
            return $this->build('not_configured', $base);
        }
        if ($info['state'] === MotrakSettingsService::TOKEN_UNREADABLE) {
            return $this->build('token_unreadable', $base + ['message' => 'Token đã lưu không đọc được. Vui lòng nhập lại Token.']);
        }

        // Log gần nhất có gọi API (bỏ qua "skipped"). Token vừa đổi sau lần kiểm tra cuối → coi như chưa kiểm tra với Token mới.
        $log = MotrakSyncLog::query()->where('status', '!=', MotrakSyncLog::STATUS_SKIPPED)->whereNotNull('completed_at')->latest('started_at')->first();
        $tokenAt = $info['updated_at'] ? Carbon::parse($info['updated_at']) : null;
        if (! $log || ($tokenAt && $log->started_at->lt($tokenAt))) {
            return $this->build('unchecked', $base);
        }

        $base['checked_at'] = $log->started_at->toIso8601String();
        $base['http_status'] = $log->http_status;

        if ($log->isOk()) {
            return $this->build('connected', $base + ['message' => $log->error_message]);
        }

        $code = match ($log->error_type) {
            MotrakApiException::UNAUTHORIZED => 'invalid_token',
            MotrakApiException::AUTH_FAILED => 'invalid_credentials',
            MotrakApiException::NO_RESPONSE => 'no_response',
            MotrakApiException::INVALID_RESPONSE => 'data_error',
            MotrakApiException::NOT_CONFIGURED => 'not_configured',
            MotrakApiException::TOKEN_UNREADABLE => 'token_unreadable',
            default => 'connection_error',
        };

        return $this->build($code, ['message' => $log->error_message] + $base);
    }

    private function build(string $code, array $base): array
    {
        return ['code' => $code, 'label' => self::LABELS[$code], 'color' => match ($code) {
            'connected' => 'success',
            'unchecked', 'not_configured' => 'gray',
            'invalid_token', 'token_unreadable', 'invalid_credentials' => 'danger',
            default => 'warning',
        }] + $base;
    }
}
