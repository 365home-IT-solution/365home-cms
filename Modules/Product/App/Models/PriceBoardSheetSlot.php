<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceBoardSheetSlot extends Model
{
    public $timestamps = false;

    protected $fillable = ['sheet_id', 'timeslot_id', 'price', 'over_night', 'status', 'sort_order'];

    protected $casts = ['over_night' => 'boolean', 'price' => 'integer'];

    public function timeslot()
    {
        return $this->belongsTo(TimeSlot::class, 'timeslot_id');
    }
}
