<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationFcm extends Model
{
    protected $table = 'notification_fcm';

    protected $fillable = [
        'title',
        'body',
        'created_by',
        'sent_count',
        'fail_count',
    ];

    protected $casts = [
        'sent_count' => 'integer',
        'fail_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationFcmRecipient::class, 'notification_fcm_id');
    }
}
