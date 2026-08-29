<?php

namespace Modules\Page\Entities;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Page\Entities\Component;
use Modules\Page\Entities\ComponentConfiguration;

/**
 * @method static where(string $string, mixed $id)
 */
class PageComponent extends Model
{
    use HasFactory, LogsAuditTrail;

    protected static function auditModuleName(): string
    {
        return 'Page';
    }

    // Không có field "name" — ghép tên trang + tên loại component để dễ đọc trong "Lịch sử thao tác".
    protected function auditLabel(): string
    {
        $pageName      = $this->page?->title ?? ('#' . $this->page_id);
        $componentName = $this->component?->label ?? ('#' . $this->component_id);

        return "Trang {$pageName} — {$componentName}";
    }

    protected $table = "comp_pages";

    protected $fillable = [
        'page_id',
        'component_id',
        'order',
    ];

    public function page()
    {
        return $this->belongsTo(Page::class);
    }

    public function component()
    {
        return $this->belongsTo(Component::class);
    }

    public function pageComponentConfigurationValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ComponentConfiguration::class,
            'comp_page_values',
            'comp_page_id',
            'comp_config_id'
        )->withPivot('value', 'type');
    }
}
