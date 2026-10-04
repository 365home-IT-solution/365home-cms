<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Lỗi nghiệp vụ khi nhận CCCD (thiếu ảnh, không đọc được QR, sai cấu trúc, dưới tuổi, trùng
 * người...). Laravel tự gọi render() → API trả về định dạng thống nhất để app biết ô nào lỗi:
 *   { "message": "...", "code": "cccd_under_age", "field": "guests.0.qr_image" }
 */
class CccdIntakeException extends \RuntimeException
{
    public const REQUIRED          = 'cccd_required';
    public const IMAGE_INVALID     = 'cccd_image_invalid';
    public const QR_UNREADABLE     = 'cccd_qr_unreadable';
    public const INVALID           = 'cccd_invalid';
    public const UNDER_AGE         = 'cccd_under_age';
    public const DUPLICATE         = 'cccd_duplicate';
    public const MISMATCH          = 'cccd_mismatch';
    public const COMPANION_INVALID = 'companion_cccd_invalid';
    public const RATE_LIMITED      = 'cccd_rate_limited';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?string $field = null,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    // Lỗi nghiệp vụ do ảnh/dữ liệu khách gửi (không phải lỗi hệ thống) — không ghi log ERROR kèm
    // stack trace cho từng lượt quét hỏng.
    public function report(): void
    {
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $this->getMessage(),
            'code'    => $this->errorCode,
            'field'   => $this->field,
        ], $this->extra), $this->status);
    }
}
