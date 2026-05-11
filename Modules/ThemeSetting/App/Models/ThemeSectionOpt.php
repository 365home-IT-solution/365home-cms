<?php

namespace Modules\ThemeSetting\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeSectionOpt extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_cfg_id',
        'option',
        'value'
    ];

    public function sectionCfg(): BelongsTo
    {
        return $this->belongsTo(ThemeSectionCfg::class);

    }
}
