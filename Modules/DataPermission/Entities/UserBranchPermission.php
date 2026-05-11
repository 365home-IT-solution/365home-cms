<?php

declare(strict_types=1);

namespace Modules\DataPermission\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Category\Entities\Category;

class UserBranchPermission extends Model
{
    protected $table = 'user_branch_permissions';

    protected $fillable = [
        'user_id',
        'category_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
