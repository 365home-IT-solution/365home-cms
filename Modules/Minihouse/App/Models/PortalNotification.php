<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 1 dòng = 1 thông báo hiện trong Portal cho ĐÚNG 1 khách thuê. Tạo qua PortalNotificationService,
// không tạo tay trực tiếp ở nơi khác để mọi nguồn thông báo đi qua đúng 1 chỗ.
class PortalNotification extends Model
{
    public const TYPE_INVOICE_NEW    = 'invoice_new';
    public const TYPE_REMINDER       = 'reminder';
    public const TYPE_ANNOUNCEMENT   = 'announcement';
    public const TYPE_FEEDBACK_REPLY = 'feedback_reply';

    protected $table = 'minihouse_portal_notifications';

    protected $fillable = ['tenant_id', 'type', 'title', 'body', 'link', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
