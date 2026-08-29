<?php

namespace Modules\ThemeSetting\App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Theme extends Model
{
    use HasFactory, LogsAuditTrail;

    protected $fillable = [
        'name',
        'description',
        'preview_image',
        'introduction',
        'is_active',
        'design_by',
        'version'
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(ThemeSection::class);
    }
}
