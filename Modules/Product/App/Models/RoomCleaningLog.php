<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Employee\Entities\Employee;
use Modules\Payment\Entities\OrderItem;

// Lịch sử "dọn vệ sinh" của từng phòng: 1 dòng/lượt — được tạo khi phòng hết giờ đặt (tự động,
// xem app/Console/Commands/MarkRoomsForCleaningCommand.php) hoặc đánh dấu thủ công, và được cập
// nhật (employee_id, cleaned_at) khi nhân viên xác nhận đã dọn xong (xem ProductAction).
class RoomCleaningLog extends Model
{
    protected $fillable = [
        'product_id',
        'order_item_id',
        'employee_id',
        'marked_for_cleaning_at',
        'cleaned_at',
        'note',
    ];

    protected $casts = [
        'marked_for_cleaning_at' => 'datetime',
        'cleaned_at'             => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isCleaned(): bool
    {
        return ! is_null($this->cleaned_at);
    }
}
