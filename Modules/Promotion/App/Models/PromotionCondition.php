<?php

namespace Modules\Promotion\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PromotionCondition extends Model
{
    use HasFactory;

    protected $fillable = [
        'promotion_id',
        'condition_type',
        'condition_value',
    ];

    protected $casts = [
        'condition_value' => 'array',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }
}
