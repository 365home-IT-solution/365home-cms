<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GHTKConfiguration extends Model
{
    use HasFactory;
    protected $table = 'ghtk_configuration';
    protected $fillable = [
        'api_token',
        'partner_code',
        'pick_name',
        'pick_address',
        'pick_province',
        'pick_district',
        'pick_ward',
        'pick_tel'
    ];
}
