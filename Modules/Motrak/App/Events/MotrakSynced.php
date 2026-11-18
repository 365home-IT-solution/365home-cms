<?php

namespace Modules\Motrak\App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Phát sau mỗi lần đồng bộ THÀNH CÔNG (kể cả một phần). Module khác lắng nghe để xử lý (VD cảnh báo xe thuê) mà không phụ thuộc ngược. */
class MotrakSynced
{
    use Dispatchable;

    /** @param  list<int>  $vehicleIds  id (motrak_vehicles.id) của các xe có vị trí MỚI trong lần đồng bộ này */
    public function __construct(public readonly array $vehicleIds, public readonly int $syncLogId)
    {
    }
}
