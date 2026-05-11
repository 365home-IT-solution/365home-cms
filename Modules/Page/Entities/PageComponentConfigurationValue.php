<?php

namespace Modules\Page\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Page\Entities\ComponentConfiguration;

class PageComponentConfigurationValue extends Model
{
    use HasFactory;

    protected $table = 'comp_page_values';

    protected $fillable = [];

    public function componentConfiguration()
    {
        return $this->belongsTo(ComponentConfiguration::class, 'comp_config_id');
    }
}
