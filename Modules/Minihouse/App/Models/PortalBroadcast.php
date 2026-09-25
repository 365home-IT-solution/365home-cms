<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Lịch sử "soạn + gửi thông báo hàng loạt" của nhân viên — xem giải thích đầy đủ ở migration
// create_minihouse_portal_broadcasts_table. Mirror App\Models\NotificationFcm (Home) về mặt UX
// (list/sửa trước khi gửi/gửi lại/lên lịch), khác ở tầng lưu trữ (không có bảng recipient riêng).
class PortalBroadcast extends Model
{
    public const SENT_FOR_ALL     = 'all';
    public const SENT_FOR_TENANTS = 'tenants';

    protected $table = 'minihouse_portal_broadcasts';

    protected $fillable = [
        'title', 'body', 'link', 'sent_for', 'tenant_ids', 'scheduled_at', 'sent_at', 'recipient_count', 'created_by',
    ];

    protected $casts = [
        'tenant_ids'   => 'array',
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
    ];

    // Đã lên lịch nhưng CHƯA tới giờ gửi (hoặc chờ cron xử lý) — mirror Home NotificationFcm::isPending().
    public function isPending(): bool
    {
        return $this->scheduled_at !== null && $this->sent_at === null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
