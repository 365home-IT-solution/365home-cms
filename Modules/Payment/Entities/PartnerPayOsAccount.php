<?php

namespace Modules\Payment\Entities;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Kênh PayOS riêng của 1 ĐỐI TÁC Homestay (áp dụng cho mọi chi nhánh của đối tác) — xem migration
// create_partner_payos_accounts_table và App\Services\Payment\PayOsAccountResolver. Cùng lý do với
// BranchPayOsAccount: KHÔNG dùng LogsAuditTrail vì audit log lưu nguyên giá trị cột, sẽ làm lộ khoá đã
// giải mã; lịch sử đổi kênh được ghi riêng (không kèm khoá) ở App\Services\Payment\PartnerPayOsChannelService.
class PartnerPayOsAccount extends Model
{
    protected $table = 'partner_payos_accounts';

    protected $fillable = [
        'partner_id',
        'is_active',
        'client_id',
        'api_key',
        'checksum_key',
        'account_holder',
        'note',
        'webhook_confirmed_at',
        'updated_by',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'client_id'            => 'encrypted',
        'api_key'              => 'encrypted',
        'checksum_key'         => 'encrypted',
        'webhook_confirmed_at' => 'datetime',
    ];

    protected $hidden = ['api_key', 'checksum_key'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isComplete(): bool
    {
        return filled($this->client_id) && filled($this->api_key) && filled($this->checksum_key);
    }

    /** @return array{0: string, 1: string, 2: string} */
    public function credentials(): array
    {
        return [$this->client_id, $this->api_key, $this->checksum_key];
    }
}
