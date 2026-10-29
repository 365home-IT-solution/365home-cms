<?php

namespace Modules\Payment\Entities;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerSePayAccount extends Model
{
    // Tên bảng theo migration (Eloquent tự suy ra "se_pay" nên không khớp).
    protected $table = 'partner_sepay_accounts';

    protected $fillable = ['partner_id', 'is_active', 'bank_code', 'bank_bin', 'account_number', 'account_holder', 'note', 'updated_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
