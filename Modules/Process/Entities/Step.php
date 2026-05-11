<?php

namespace Modules\Process\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Step extends Model
{
    use HasFactory;

    protected $fillable = [
        'process_id',
        'name',
        'description',
        'icon',
        'order'
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }
}
