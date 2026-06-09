<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationFcmRecipient extends Model
{
    protected $table = 'notification_fcm_recipients';

    protected $fillable = [
        'notification_fcm_id',
        'customer_id',
        'fcm_token',
        'status',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(NotificationFcm::class, 'notification_fcm_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
