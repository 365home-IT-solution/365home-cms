<?php

namespace Modules\Category\Traits;

use Modules\Category\Entities\Category;

trait Categorizable
{
    public function categories()
    {
        return $this->morphToMany(Category::class, 'categorizable');
    }

    public function syncCategories($categories)
    {
        $this->categories()->sync($categories);
    }

    protected static function bootCategorizable()
    {
        static::saved(function ($model) {
            if (request()->has('categories')) {
                $model->syncCategories(request()->input('categories'));
            }
        });
    }
}
