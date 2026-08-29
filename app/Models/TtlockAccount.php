<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPartner;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Category\Entities\Category;

class TtlockAccount extends Model
{
    use BelongsToPartner, LogsAuditTrail;

    protected $fillable = [
        'partner_id',
        'name',
        'client_id',
        'client_secret',
        'username',
        'password_md5',
        'api_base',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'ttlock_account_category');
    }
}
