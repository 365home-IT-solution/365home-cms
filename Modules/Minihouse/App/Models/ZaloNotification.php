<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Lịch sử gửi ZNS cho khách thuê MiniHouse — xem MinihouseZaloService::sendZns(). Bảng riêng, không
// chung với zns_notifications của Home (khác tài khoản Zalo, khác đối tượng gắn theo — Reminder ở
// đây thay vì Order bên Home).
class ZaloNotification extends Model
{
    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $table = 'minihouse_zalo_notifications';

    protected $fillable = [
        'reminder_id', 'phone_number', 'recipient_name', 'template_id', 'template_data',
        'status', 'zalo_message_id', 'error_message', 'sent_at',
    ];

    protected $casts = [
        'template_data' => 'array',
        'sent_at'       => 'datetime',
    ];

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
