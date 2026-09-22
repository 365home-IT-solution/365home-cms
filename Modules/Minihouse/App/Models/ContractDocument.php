<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;

// Hợp đồng điện tử (Mức A) gắn 1-1 với Contract — xem docs/be-minihouse-contract-signing.md.
// KHÔNG SoftDeletes: không có API xoá bản ghi này (kể cả cancelled), chỉ đổi status.
class ContractDocument extends Model
{
    use LogsMinihouseActivity;

    public const STATUS_DRAFT           = 'draft';
    public const STATUS_AWAITING_TENANT = 'awaiting_tenant';
    public const STATUS_AWAITING_OWNER  = 'awaiting_owner';
    public const STATUS_SIGNED          = 'signed';
    public const STATUS_CANCELLED       = 'cancelled';

    protected $table = 'minihouse_contract_documents';

    protected $fillable = [
        'contract_id', 'status', 'no', 'sign_date', 'signed_place', 'max_occupants', 'payment_day',
        'extra_terms', 'snapshot', 'template_version',
        'sealed_pdf_path', 'sealed_hash', 'sealed_at',
        'final_pdf_path', 'final_hash',
        'verify_code', 'sent_at',
    ];

    protected $casts = [
        'sign_date'     => 'date',
        'snapshot'      => 'array',
        'sealed_at'     => 'datetime',
        'sent_at'       => 'datetime',
        'max_occupants' => 'integer',
        'payment_day'   => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class, 'document_id')->orderBy('signed_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContractDocumentEvent::class, 'document_id')->orderBy('created_at');
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_AWAITING_TENANT, self::STATUS_AWAITING_OWNER], true);
    }

    protected function activityLabel(): string
    {
        return $this->no ?: ('#' . $this->id);
    }
}
