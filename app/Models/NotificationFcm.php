<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationFcm extends Model
{
    use LogsAuditTrail;

    protected $table = 'notification_fcm';

    protected $fillable = [
        'title',
        'body',
        'url',
        'type',
        'data',
        'sent_for',
        'scheduled_at',
        'sent_at',
        'recipient_ids',
        'created_by',
        'sent_count',
        'fail_count',
    ];

    protected $casts = [
        'sent_count'    => 'integer',
        'fail_count'    => 'integer',
        'scheduled_at'  => 'datetime',
        'sent_at'       => 'datetime',
        'recipient_ids' => 'array',
        'data'          => 'array',
    ];

    public function isPending(): bool
    {
        return $this->scheduled_at !== null && $this->sent_at === null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationFcmRecipient::class, 'notification_fcm_id');
    }
}
