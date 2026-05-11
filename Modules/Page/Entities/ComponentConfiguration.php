<?php

namespace Modules\Page\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Page\Entities\PageComponent;

/**
 * @method static whereIn(string $string, int[]|string[] $array_keys)
 */
class ComponentConfiguration extends Model
{
    use HasFactory;

    protected $table = "comp_configs";

    protected $fillable = [
        'component_id', 
        'name', 
        'label', 
        'type', 
        'type_field', 
        'field_set', 
        'has_options'
    ];

    public function component(): BelongsTo {
        return $this->belongsTo(Component::class);
    }

    public function pageComponentConfigurationValues(): BelongsToMany
    {
        return $this->belongsToMany(
            PageComponent::class,
            'comp_page_values',
            'comp_config_id',
            'comp_page_id'
        )->withPivot('value', 'type');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ComponentConfigOption::class, 'comp_config_id');
    }
}
