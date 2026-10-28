<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Lỗi nghiệp vụ khi sao chép phòng (App\Services\RoomCopyService) — dùng chung cho nút "Sao chép"
 * trên CMS và API /api/admin/rooms/{id}/copy để cả hai báo lỗi giống nhau.
 */
class RoomCopyException extends \RuntimeException
{
    public const BUSY        = 'room_copy_busy';
    public const NAME_TAKEN  = 'room_copy_name_taken';
    public const INTERRUPTED = 'room_copy_interrupted';

    /**
     * @param  list<string>  $names  NAME_TAKEN: tên đã có phòng dùng; INTERRUPTED: tên các phòng đã tạo xong
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $names = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function busy(): self
    {
        return new self('Đang có một lượt sao chép phòng khác của đối tác này, vui lòng thử lại sau ít phút.', self::BUSY, 409);
    }

    /** @param list<string> $taken */
    public static function nameTaken(array $taken): self
    {
        return new self('Đã có phòng mang tên: ' . implode(', ', $taken) . '. Chọn số bắt đầu khác hoặc để trống để tự lấy số kế tiếp.', self::NAME_TAKEN, 422, $taken);
    }

    /** @param list<string> $created */
    public static function interrupted(array $created, \Throwable $previous): self
    {
        return new self(
            'Sao chép phòng bị gián đoạn giữa chừng. Các phòng đã tạo được giữ lại — kiểm tra danh sách phòng rồi sao chép phần còn thiếu.',
            self::INTERRUPTED,
            500,
            $created,
            $previous,
        );
    }
}
