<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

// ĐỀ XUẤT TRỪ KÝ QUỸ: Super Admin tạo → đối tác đồng ý hoặc khiếu nại trong hạn (hết hạn không phản hồi = đồng ý) →
// chốt thì mới sinh bút toán trừ. Trong lúc chờ, số tiền tính vào "đang tạm giữ". Đề xuất KHẨN (is_urgent) trừ ngay
// khi tạo, đối tác vẫn khiếu nại được sau — xem App\Services\EscrowService.
class PartnerEscrowDeduction extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING   => 'Chờ đối tác phản hồi',
        self::STATUS_DISPUTED  => 'Đối tác khiếu nại',
        self::STATUS_APPLIED   => 'Đã trừ',
        self::STATUS_CANCELLED => 'Đã huỷ',
    ];

    public const RESOLUTION_KEEP = 'keep';
    public const RESOLUTION_REDUCE = 'reduce';
    public const RESOLUTION_CANCEL = 'cancel';

    // Các loại trừ đi qua bước đề xuất (trừ hoa hồng quá hạn là tự động, không qua đây).
    public const TYPES = [
        PartnerEscrowEntry::TYPE_DEDUCT_REFUND,
        PartnerEscrowEntry::TYPE_DEDUCT_PENALTY,
        PartnerEscrowEntry::TYPE_DEDUCT_DAMAGE,
        PartnerEscrowEntry::TYPE_DEDUCT_OTHER,
    ];

    // Các trường hợp được trừ ký quỹ trong phụ lục hợp đồng (trường hợp 1 — hoa hồng quá hạn — tự động, không qua đề xuất).
    public const CASE_REFUND_NOT_RETURNED = 'refund_not_returned';
    public const CASE_HOST_CANCELLED = 'host_cancelled';
    public const CASE_DAMAGE = 'damage_compensation';
    public const CASE_CHARGEBACK = 'chargeback';
    public const CASE_CONTRACT_VIOLATION = 'contract_violation';
    public const CASE_OTHER_AGREED = 'other_agreed';

    public const CASES = [
        self::CASE_REFUND_NOT_RETURNED => 'Khách được hoàn tiền mà đối tác không hoàn trong 24 giờ — 365home hoàn thay',
        self::CASE_HOST_CANCELLED      => 'Đối tác huỷ/không giao phòng đơn đã xác nhận (phạt)',
        self::CASE_DAMAGE              => 'Bồi thường thiệt hại cho khách do lỗi cơ sở',
        self::CASE_CHARGEBACK          => 'Khiếu nại/hoàn giao dịch (chargeback) từ cổng thanh toán',
        self::CASE_CONTRACT_VIOLATION  => 'Vi phạm hợp đồng đã quy định mức phạt',
        self::CASE_OTHER_AGREED        => 'Khoản khác hai bên thoả thuận bằng văn bản',
    ];

    protected $fillable = [
        'partner_id', 'type', 'case_code', 'amount', 'final_amount', 'status', 'is_urgent', 'reason', 'order_code', 'reference', 'respond_by',
        'dispute_reason', 'disputed_at', 'disputed_by', 'resolution', 'resolution_note', 'resolved_at', 'resolved_by', 'applied_at', 'created_by',
    ];

    protected $casts = [
        'amount'       => 'integer',
        'final_amount' => 'integer',
        'is_urgent'    => 'boolean',
        'respond_by'   => 'datetime',
        'disputed_at'  => 'datetime',
        'resolved_at'  => 'datetime',
        'applied_at'   => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence');
        $this->addMediaCollection('dispute_evidence');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PartnerEscrowEntry::class, 'deduction_id');
    }

    /** Đối tác còn đồng ý/khiếu nại được không: chưa từng khiếu nại, chưa chốt và còn trong hạn phản hồi. */
    public function canRespond(): bool
    {
        $open = $this->status === self::STATUS_PENDING || ($this->is_urgent && $this->status === self::STATUS_APPLIED && $this->resolved_at === null);

        return $open && $this->disputed_at === null && ($this->respond_by === null || $this->respond_by->isFuture());
    }

    public function toApi(): array
    {
        $files = fn (string $collection) => $this->getMedia($collection)->map(fn ($media) => ['name' => $media->file_name, 'mime_type' => $media->mime_type, 'size' => $media->size, 'url' => $media->getUrl()])->values();

        return [
            'id'               => $this->id,
            'type'             => $this->type,
            'type_label'       => PartnerEscrowEntry::TYPES[$this->type] ?? $this->type,
            'case_code'        => $this->case_code,
            'case_label'       => self::CASES[$this->case_code] ?? null,
            'amount'           => $this->amount,
            // Số tiền thực trừ sau khi chốt (khác amount khi Super Admin giảm).
            'final_amount'     => $this->final_amount,
            'status'           => $this->status,
            'status_label'     => self::STATUSES[$this->status] ?? $this->status,
            'is_urgent'        => $this->is_urgent,
            'reason'           => $this->reason,
            'order_code'       => $this->order_code,
            'reference'        => $this->reference,
            'respond_by'       => $this->respond_by?->toIso8601String(),
            'can_respond'      => $this->canRespond(),
            'dispute_reason'   => $this->dispute_reason,
            'disputed_at'      => $this->disputed_at?->toIso8601String(),
            'resolution'       => $this->resolution,
            'resolution_note'  => $this->resolution_note,
            'resolved_at'      => $this->resolved_at?->toIso8601String(),
            'applied_at'       => $this->applied_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
            'evidence'         => $files('evidence'),
            'dispute_evidence' => $files('dispute_evidence'),
        ];
    }
}
