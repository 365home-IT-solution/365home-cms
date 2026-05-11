<?php

namespace Modules\Page\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentConfigOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'comp_config_id',
        'option_label',
        'option_value',
    ];

    protected $table = 'comp_config_options';
    
    public function config(): BelongsTo
    {
        return $this->belongsTo(ComponentConfiguration::class, 'comp_config_id');
    }
}
