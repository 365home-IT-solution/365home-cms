<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Lịch sử gửi SMS cho khách thuê MiniHouse — xem MinihouseSmsService::sendSms(). Cùng mẫu
// ZaloNotification, bảng riêng cho kênh SMS.
class SmsNotification extends Model
{
    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $table = 'minihouse_sms_notifications';

    protected $fillable = [
        'reminder_id', 'phone_number', 'recipient_name', 'content',
        'status', 'sms_id', 'error_message', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
