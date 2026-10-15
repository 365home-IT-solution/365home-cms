<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceTemplateSlot extends Model
{
    protected $fillable = [
        'price_template_id',
        'timeslot_id',
        'price',
        'checkin',
        'checkout',
        'over_night',
        'sort_order',
    ];

    protected $casts = [
        'price'      => 'integer',
        'over_night' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(PriceTemplate::class, 'price_template_id');
    }

    public function timeSlot()
    {
        return $this->belongsTo(TimeSlot::class, 'timeslot_id');
    }
}
