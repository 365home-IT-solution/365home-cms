<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;

// Một phiên liên kết ngân hàng Pay2S (Hosted Link) của đối tác. `applied` = đã ghi tài khoản vào partner_sepay_accounts.
class PartnerPay2sLinkSession extends Model
{
    protected $table = 'partner_pay2s_link_sessions';

    protected $fillable = ['partner_id', 'category_id', 'session_id', 'customer_reference', 'status', 'hosted_link_url', 'expires_at', 'error_code', 'error_message', 'applied', 'account', 'created_by'];

    protected $casts = ['expires_at' => 'datetime', 'applied' => 'boolean', 'account' => 'array'];

    public const PENDING = ['created', 'sending', 'awaiting_otp', 'verifying', 'uncertain'];

    public const FAILED = ['cancelled', 'failed', 'expired', 'revoked'];

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true) && (! $this->expires_at || $this->expires_at->isFuture() || $this->status !== 'created');
    }
}
