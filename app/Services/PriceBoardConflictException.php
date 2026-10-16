<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Bảng giá trùng ngày với bảng đang bật khác cho cùng phòng — kèm danh sách xung đột có cấu trúc. */
class PriceBoardConflictException extends RuntimeException
{
    /** @param list<array{board: array{id:int,name:string,priority:int}, product_ids: list<string>}> $conflicts */
    public function __construct(string $message, public readonly array $conflicts = [])
    {
        parent::__construct($message);
    }
}
