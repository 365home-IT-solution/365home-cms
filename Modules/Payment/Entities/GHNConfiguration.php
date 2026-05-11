<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GHNConfiguration extends Model
{
    use HasFactory;
    protected $table = 'ghn_configuration';
    protected $fillable = [
        'api_token', 
        'client_id',
        'shop_id',
        'payment_type_id',
        'required_note',
        'return_phone',
        'return_address',
        'return_district_id',
        'return_ward_code',
        'from_name',
        'from_phone',
        'from_address',
        'from_ward_name',
        'from_district_name',
        'from_province_name'
    ];
}
