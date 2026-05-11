<?php

namespace Modules\ThemeSetting\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static where(string $string, string $string1)
 * @method getChildConfig(string $string)
 * @property mixed $children
 */
class ThemeSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'label',
        'description',
        'icon',
        'parent_id',
        'theme_id'
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function sectionCfgs(): HasMany
    {
        return $this->hasMany(ThemeSectionCfg::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ThemeSection::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ThemeSection::class, 'parent_id');
    }
}
