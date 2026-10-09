<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

// Khiếu nại 1 đơn trong bảng đối soát (App\Services\SettlementService::disputeOrder()).
class PartnerSettlementDispute extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const STATUS_OPEN = 'open';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'settlement_id', 'order_id', 'order_code', 'status', 'reason', 'adjusted_commission',
        'resolution_note', 'created_by', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'adjusted_commission' => 'integer',
        'resolved_at'         => 'datetime',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PartnerSettlement::class, 'settlement_id');
    }
}
