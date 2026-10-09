<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

// Một bút toán của SỔ KÝ QUỸ đối tác. Số dư ký quỹ chỉ đổi qua bút toán (App\Services\EscrowService::post());
// bút toán KHÔNG sửa/xoá được — ghi sai thì ghi bút toán đảo (type 'reversal').
class PartnerEscrowEntry extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_DEDUCT_COMMISSION = 'deduct_commission';
    public const TYPE_DEDUCT_REFUND = 'deduct_refund';
    public const TYPE_DEDUCT_PENALTY = 'deduct_penalty';
    public const TYPE_DEDUCT_DAMAGE = 'deduct_damage';
    public const TYPE_DEDUCT_OTHER = 'deduct_other';
    public const TYPE_REVERSAL = 'reversal';
    public const TYPE_WITHDRAW = 'withdraw';

    public const TYPES = [
        self::TYPE_DEPOSIT           => 'Nạp ký quỹ',
        self::TYPE_DEDUCT_COMMISSION => 'Trừ hoa hồng quá hạn',
        self::TYPE_DEDUCT_REFUND     => 'Trừ khoản 365home hoàn tiền khách thay đối tác',
        self::TYPE_DEDUCT_PENALTY    => 'Trừ tiền phạt vi phạm',
        self::TYPE_DEDUCT_DAMAGE     => 'Trừ tiền bồi thường',
        self::TYPE_DEDUCT_OTHER      => 'Trừ khoản khác',
        self::TYPE_REVERSAL          => 'Đảo bút toán',
        self::TYPE_WITHDRAW          => 'Hoàn ký quỹ',
    ];

    public const UPDATED_AT = null;

    protected $fillable = [
        'partner_id', 'type', 'amount', 'balance_after', 'reason', 'order_code', 'reference',
        'deduction_id', 'deposit_id', 'reverses_entry_id', 'is_urgent', 'created_by', 'created_at',
    ];

    protected $casts = [
        'amount'        => 'integer',
        'balance_after' => 'integer',
        'is_urgent'     => 'boolean',
        'created_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \DomainException('Bút toán ký quỹ không sửa được — hãy ghi bút toán đảo.'));
        static::deleting(fn () => throw new \DomainException('Bút toán ký quỹ không xoá được — hãy ghi bút toán đảo.'));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    public function toApi(): array
    {
        return [
            'id'                => $this->id,
            'type'              => $this->type,
            'type_label'        => self::TYPES[$this->type] ?? $this->type,
            'amount'            => $this->amount,
            'balance_after'     => $this->balance_after,
            'reason'            => $this->reason,
            'order_code'        => $this->order_code,
            'reference'         => $this->reference,
            'deduction_id'      => $this->deduction_id,
            'deposit_id'        => $this->deposit_id,
            'reverses_entry_id' => $this->reverses_entry_id,
            'is_reversed'       => $this->reversedBy()->exists(),
            'is_urgent'         => $this->is_urgent,
            // null = hệ thống tự ghi (webhook PayOS, hết hạn phản hồi...).
            'created_by'        => $this->creator?->only(['id', 'fullname']),
            'created_at'        => $this->created_at?->toIso8601String(),
            'evidence'          => $this->getMedia('evidence')->map(fn ($media) => ['name' => $media->file_name, 'mime_type' => $media->mime_type, 'size' => $media->size, 'url' => $media->getUrl()])->values(),
        ];
    }
}
