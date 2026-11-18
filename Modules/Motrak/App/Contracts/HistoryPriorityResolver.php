<?php

namespace Modules\Motrak\App\Contracts;

use Modules\Motrak\App\Models\MotrakVehicle;

/**
 * Module khác (VD thuê xe) cho biết xe nào cần lưu lịch sử ĐẦY ĐỦ (mọi điểm mới) thay vì lấy mẫu thưa — VD xe đang trong chuyến thuê.
 * Mặc định không xe nào được ưu tiên (xem MotrakServiceProvider).
 */
interface HistoryPriorityResolver
{
    public function needsFullHistory(MotrakVehicle $vehicle): bool;
}
