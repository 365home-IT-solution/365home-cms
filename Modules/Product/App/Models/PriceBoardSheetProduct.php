<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceBoardSheetProduct extends Model
{
    public $timestamps = false;

    protected $fillable = ['sheet_id', 'product_id'];
}
