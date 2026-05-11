<?php

namespace Modules\Promotion\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PromotionCodeUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'promotion_code_id',
        'user_id',
        'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function promotionCode()
    {
        return $this->belongsTo(PromotionCode::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

}
