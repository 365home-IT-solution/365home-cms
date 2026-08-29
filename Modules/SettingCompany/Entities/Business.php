<?php

namespace Modules\SettingCompany\Entities;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Footer\Entities\FooterColumn;

class Business extends Model
{
    use LogsAuditTrail;

    protected $fillable = [
        'name',
        'slug',
        'address',
        'phone',
        'email',
        'website',
        'tax_code',
        'description',
        'image',
    ];

    public function branches()
    {
        return $this->hasMany(Branch::class); // Quan hệ hasMany
    }

    public function footerColumn(): BelongsTo {
        return $this->belongsTo(FooterColumn::class);
    }
}
