<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Category\Entities\Category;

// Một mảnh của chiến dịch = một chi nhánh tham gia (kèm ảnh mảnh).
class CollectionPiece extends Model
{
    protected $fillable = ['campaign_id', 'branch_id', 'image', 'sort_order'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'branch_id')->withoutGlobalScopes();
    }
}
