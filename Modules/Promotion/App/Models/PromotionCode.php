<?php

namespace Modules\Promotion\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PromotionCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'promotion_id',
        'code',
        'usage_limit',
        'used_count',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function usages()
    {
        return $this->hasMany(PromotionCodeUsage::class);
    }
}
