<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 1 lần khách xác thực CCCD (lần 1, 2, 3...) — xem migration
// 2026_09_28_200000_create_customer_cccd_verifications_table.
class CustomerCccdVerification extends Model
{
    public const SOURCE_WEB_ACCOUNT = 'web_account';
    public const SOURCE_APP         = 'app';
    public const SOURCE_LEGACY      = 'legacy';

    protected $fillable = [
        'customer_id',
        'attempt',
        'cccd_qr_image',
        'cccd_data',
        'same_as_first',
        'source',
    ];

    protected $casts = [
        'cccd_data'       => 'array',
        'same_as_first'   => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
