<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Lịch sử thay đổi trạng thái xác minh hồ sơ đối tác (pending/approved/suspended/rejected) —
// ghi lại mỗi khi super_admin bấm "Phê duyệt chính thức" / "Tạm dừng hồ sơ", hiển thị ở tab
// "Lịch sử" (PartnerResource).
class PartnerStatusLog extends Model
{
    protected $fillable = [
        'partner_id',
        'from_status',
        'to_status',
        'note',
        'changed_by',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
