<?php

namespace Modules\Payment\Entities;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerSePayAccount extends Model
{
    // Tên bảng theo migration (Eloquent tự suy ra "se_pay" nên không khớp).
    protected $table = 'partner_sepay_accounts';

    public const PROVIDER_SEPAY = 'sepay';

    public const PROVIDER_PAY2S = 'pay2s';

    public const PROVIDERS = [self::PROVIDER_SEPAY => 'SePay', self::PROVIDER_PAY2S => 'Pay2S'];

    protected $fillable = ['partner_id', 'provider', 'is_active', 'bank_code', 'bank_bin', 'account_number', 'account_holder', 'note', 'webhook_token', 'updated_by'];

    // webhook_token: token Pay2S sinh cho webhook của tài khoản này (mã hoá khi lưu, không bao giờ trả ra API/form).
    protected $casts = ['is_active' => 'boolean', 'webhook_token' => 'encrypted'];

    protected $hidden = ['webhook_token'];

    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider] ?? ucfirst((string) $this->provider);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
