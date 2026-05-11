<?php

namespace Modules\Category\Entities;

use Illuminate\Database\Eloquent\Model;

class Categorizable extends Model
{
    protected $table = 'categorizables';

    protected $fillable = [
        'category_id',
        'categorizable_id',
        'categorizable_type',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function categorizable()
    {
        return $this->morphTo();
    }
}