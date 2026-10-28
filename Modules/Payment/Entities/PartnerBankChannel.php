<?php

namespace Modules\Payment\Entities;

use App\Models\Partner;
use App\Support\Banks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Kênh chuyển khoản ngân hàng (VietQR + SePay) của 1 ĐỐI TÁC Homestay — xem migration create_partner_bank_channels_table và
// App\Services\Payment\SepayWebhookService. Cùng lý do với PartnerPayOsAccount: KHÔNG dùng LogsAuditTrail để không lộ webhook_key.
class PartnerBankChannel extends Model
{
    protected $table = 'partner_bank_channels';

    protected $fillable = [
        'partner_id',
        'is_active',
        'bank_code',
        'account_number',
        'account_holder',
        'webhook_key',
        'webhook_key_hash',
        'last_webhook_at',
        'note',
        'updated_by',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'webhook_key'     => 'encrypted',
        'last_webhook_at' => 'datetime',
    ];

    protected $hidden = ['webhook_key', 'webhook_key_hash'];

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function bank(): ?array
    {
        return Banks::find($this->bank_code);
    }

    // Số tài khoản SePay báo về có phải của kênh này không (so cả tài khoản ảo/subAccount nếu đối tác dùng VA).
    public function ownsAccount(?string ...$accountNumbers): bool
    {
        foreach ($accountNumbers as $number) {
            if (filled($number) && trim((string) $number) === $this->account_number) {
                return true;
            }
        }

        return false;
    }
}
