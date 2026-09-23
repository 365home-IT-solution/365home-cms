<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Nhật ký chỉ-thêm của 1 bản hợp đồng điện tử — in kèm dưới dạng "Biên bản quá trình ký" (GET
// .../document/audit). Không dùng LogsMinihouseActivity — bảng này tự nó là 1 hệ log riêng.
class ContractDocumentEvent extends Model
{
    public const EVENT_SEALED           = 'sealed';
    public const EVENT_SENT             = 'sent';
    public const EVENT_VIEWED_BY_TENANT = 'viewed_by_tenant';
    public const EVENT_OTP_SENT         = 'otp_sent';
    public const EVENT_OTP_FAILED       = 'otp_failed';
    public const EVENT_SIGNED_BY_TENANT = 'signed_by_tenant';
    public const EVENT_SIGNED_BY_OWNER  = 'signed_by_owner';
    public const EVENT_FINALIZED        = 'finalized';
    public const EVENT_RECALLED         = 'recalled';
    public const EVENT_CANCELLED        = 'cancelled';

    public const ACTOR_TENANT = 'minihouse_tenant';
    public const ACTOR_ADMIN  = 'admin_user';
    public const ACTOR_SYSTEM = 'system';

    public $timestamps = false;

    protected $table = 'minihouse_contract_document_events';

    protected $fillable = [
        'document_id', 'event', 'actor_type', 'actor_id', 'actor_name', 'ip', 'user_agent', 'meta',
        'created_at',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ContractDocument::class, 'document_id');
    }
}
