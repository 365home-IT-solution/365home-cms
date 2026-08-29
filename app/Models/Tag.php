<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Spatie\Tags\Tag as BaseTag;

class Tag extends BaseTag
{
    use LogsAuditTrail;

    public array $translatable = ['name', 'slug', 'image'];

    public $guarded = [];
}
