<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Không duyệt / không xử lý được yêu cầu (đã xử lý rồi, không đủ quyền, bản sửa không còn áp dụng được...) — message hiển thị thẳng cho người thao tác. */
class ApprovalException extends \DomainException
{
}
