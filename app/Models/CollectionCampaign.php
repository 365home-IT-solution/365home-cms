<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Chiến dịch sưu tầm mảnh ghép theo chi nhánh — xem App\Services\CollectionService.
class CollectionCampaign extends Model
{
    protected $fillable = [
        'name', 'description', 'cover_image', 'start_at', 'end_at', 'redeem_until', 'pieces_required', 'reward_label',
        'coupon_type', 'coupon_value', 'min_order_value', 'max_discount', 'validity_days', 'is_active',
    ];

    protected $casts = [
        'start_at'        => 'datetime',
        'end_at'          => 'datetime',
        'redeem_until'    => 'datetime',
        'is_active'       => 'boolean',
        'pieces_required' => 'integer',
        'coupon_value'    => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'max_discount'    => 'decimal:2',
    ];

    public function pieces(): HasMany
    {
        return $this->hasMany(CollectionPiece::class, 'campaign_id')->orderBy('sort_order')->orderBy('id');
    }

    public function customerPieces(): HasMany
    {
        return $this->hasMany(CollectionCustomerPiece::class, 'campaign_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CollectionRedemption::class, 'campaign_id');
    }

    // Đã bắt đầu và đã có khách nhận mảnh ⇒ không được đổi luật (chi nhánh tham gia, pieces_required).
    public function isLocked(): bool
    {
        return $this->start_at->isPast() && $this->customerPieces()->exists();
    }
}
