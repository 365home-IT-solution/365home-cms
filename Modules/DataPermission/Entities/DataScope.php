<?php

declare(strict_types=1);

namespace Modules\DataPermission\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataScope extends Model
{
    protected $table = 'user_data_scopes';

    protected $fillable = [
        'user_id',
        'table_name',
        'can_view',
        'scope_own_data',
        'owner_column',
        'notes',
    ];

    protected $casts = [
        'can_view'       => 'boolean',
        'scope_own_data' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
