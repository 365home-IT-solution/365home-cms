<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Promotion\App\Models\Coupon;

// Lịch sử cộng / trừ điểm game.
class GamePointTransaction extends Model
{
    public const TYPE_EARN   = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_ADJUST = 'adjust';

    public const TYPE_LABELS = [
        self::TYPE_EARN   => 'Thắng game',
        self::TYPE_REDEEM => 'Đổi voucher',
        self::TYPE_ADJUST => 'Điều chỉnh',
    ];

    public const SOURCE_TIEN_LEN = 'tien_len';
    public const SOURCE_REWARD   = 'reward';
    public const SOURCE_ADMIN    = 'admin';

    protected $fillable = ['customer_id', 'amount', 'balance_after', 'type', 'source', 'reference_id', 'reward_id', 'coupon_id', 'note', 'created_by'];

    protected $casts = [
        'amount'        => 'integer',
        'balance_after' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(GamePointReward::class, 'reward_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id')->withoutGlobalScopes();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
