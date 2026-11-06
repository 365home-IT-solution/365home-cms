<?php

declare(strict_types=1);

namespace App\Games\TienLen;

use App\Models\TienLenMatch;

// App gửi nước đi theo trạng thái cũ (seq lệch) hoặc đã quá giờ lượt — trả 409 kèm trạng thái mới nhất để app vẽ lại.
final class StaleMatchException extends \RuntimeException
{
    public ?TienLenMatch $match = null;

    public array $events = [];
}
