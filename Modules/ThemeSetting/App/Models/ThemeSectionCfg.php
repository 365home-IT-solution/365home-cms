<?php

namespace Modules\ThemeSetting\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Enums\ConfigDataType;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Enums\FieldInputType;

use function PHPSTORM_META\map;

/**
 * @property mixed $data_type
 */
class ThemeSectionCfg extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_id',
        'key',
        'label',
        'value',
        'default_value',
        'min_value',
        'max_value',
        'step_value',
        'suffix_value',
        'field_type',
        'help_text',
        'group_name',
        'has_option',
        'data_type'
    ];

    protected $casts = [
        'data_type' => ConfigDataType::class
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->data_type)) {
                $fieldType = FieldInputType::fromString($model->field_type);
                $model->data_type = $fieldType->getDataType()->value;
            }
        });
    }

    public function getValueAttribute($value)
    {
        if ($this->data_type) {
            return $this->data_type->formatValue($value);
        }
        return $value;
    }

    public function setValueAttribute($value): void
    {
        if ($this->data_type) {
            $this->attributes['value'] = $this->data_type->prepareForStorage($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    public function sectionOpts(): HasMany
    {
        return $this->hasMany(ThemeSectionOpt::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ThemeSection::class);
    }
}
