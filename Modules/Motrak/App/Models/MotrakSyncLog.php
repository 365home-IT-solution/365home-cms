<?php

namespace Modules\Motrak\App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nhật ký mỗi lần gọi API Motrak (đồng bộ tay, tự động, kiểm tra kết nối). Trạng thái kết nối hiện tại suy ra từ bản ghi gần nhất. */
class MotrakSyncLog extends Model
{
    protected $table = 'motrak_sync_logs';

    public const TYPE_MANUAL = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_TEST = 'test';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'sync_type', 'status', 'total_vehicles', 'success_count', 'failed_count', 'new_locations', 'error_type', 'http_status',
        'error_message', 'triggered_by', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'total_vehicles' => 'integer',
        'success_count'  => 'integer',
        'failed_count'   => 'integer',
        'new_locations'  => 'integer',
        'http_status'    => 'integer',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
    ];

    public function isOk(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_PARTIAL], true);
    }

    public function durationMs(): ?int
    {
        return $this->completed_at && $this->started_at ? (int) round($this->started_at->diffInMilliseconds($this->completed_at)) : null;
    }
}
