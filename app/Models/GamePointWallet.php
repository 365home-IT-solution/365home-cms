<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Ví điểm game của khách — chỉ thay đổi qua App\Services\GamePointService (có khoá dòng).
class GamePointWallet extends Model
{
    protected $primaryKey = 'customer_id';
    protected $keyType    = 'string';
    public $incrementing  = false;

    protected $fillable = ['customer_id', 'balance', 'total_earned', 'total_redeemed'];

    protected $casts = [
        'balance'        => 'integer',
        'total_earned'   => 'integer',
        'total_redeemed' => 'integer',
    ];
}
