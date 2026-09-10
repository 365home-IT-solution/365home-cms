<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

// Xem Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity — model nào gắn trait đó thì mọi
// lần tạo/sửa/xoá tự sinh 1 dòng ở đây, không cần đăng ký gì thêm.
class ActivityLog extends Model
{
    use ScopedToActiveBuildingId;

    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';

    protected $table = 'minihouse_activity_logs';

    protected $fillable = [
        'building_id', 'user_id', 'user_name', 'action',
        'subject_type', 'subject_id', 'subject_label', 'old_values', 'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subjectLabel(): string
    {
        return $this->subject_label ?: ('#' . $this->subject_id);
    }

    public function subjectTypeLabel(): string
    {
        return \Modules\Minihouse\App\Support\ActivityLogFormatter::modelLabel($this->subject_type);
    }
}
