<?php

namespace Modules\Motrak\App\Exceptions;

use RuntimeException;

/**
 * Lỗi khi gọi API Motrak. type phân loại lỗi (dùng cho trạng thái trên CMS); message đã được làm sạch — KHÔNG bao giờ chứa Token.
 */
class MotrakApiException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const TOKEN_UNREADABLE = 'token_unreadable';
    public const UNAUTHORIZED = 'unauthorized';
    public const NOT_FOUND = 'not_found';
    public const RATE_LIMITED = 'rate_limited';
    public const SERVER_ERROR = 'server_error';
    public const NO_RESPONSE = 'no_response';
    public const CONNECTION = 'connection';
    public const INVALID_RESPONSE = 'invalid_response';

    public function __construct(public readonly string $type, string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
