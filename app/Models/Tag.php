<?php

namespace App\Models;

use Spatie\Tags\Tag as BaseTag;

class Tag extends BaseTag
{
    public array $translatable = ['name', 'slug', 'image'];

    public $guarded = [];
}
