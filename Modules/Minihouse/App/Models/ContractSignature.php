<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Mỗi dòng = 1 lần ký thật (khách hoặc chủ) — CHỈ INSERT, không update/delete (xem
// ContractDocumentService). Bảng này TỰ NÓ là bằng chứng/nhật ký, không dùng LogsMinihouseActivity.
class ContractSignature extends Model
{
    public const PARTY_TENANT = 'tenant';
    public const PARTY_OWNER  = 'owner';

    public const SIGNER_TYPE_TENANT = 'minihouse_tenant';
    public const SIGNER_TYPE_ADMIN  = 'admin_user';

    public const AUTH_METHOD_OTP_ZALO     = 'otp_zalo';
    public const AUTH_METHOD_OTP_SMS      = 'otp_sms';
    public const AUTH_METHOD_ADMIN_SESSION = 'admin_session';

    public $timestamps = false;

    protected $table = 'minihouse_contract_signatures';

    protected $fillable = [
        'document_id', 'party', 'signer_type', 'signer_id', 'signer_name', 'signer_phone',
        'signature_path', 'signed_document_hash', 'signed_at', 'ip', 'user_agent',
        'auth_method', 'otp_request_id', 'otp_verified_at', 'consent_text', 'created_at',
    ];

    protected $casts = [
        'signed_at'       => 'datetime',
        'otp_verified_at' => 'datetime',
        'created_at'      => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ContractDocument::class, 'document_id');
    }
}
