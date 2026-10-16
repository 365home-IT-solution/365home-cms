<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceBoardRoom extends Model
{
    public $timestamps = false;

    protected $fillable = ['price_board_id', 'product_id'];
}
