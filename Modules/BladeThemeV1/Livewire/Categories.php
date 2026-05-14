<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class Categories extends Component
{
    use HandleConfigTrait;

    public $categories;

    public function mount($config)
    {
        $this->setConfig($config);
        $this->uniqueId = $this->generateUniqueId('owl');
        $this->categories = Category::query()
            ->select([
                'c1.name as c1_name',
                'c2.name as c2_name',
                'c3.name as c3_name',
                'c3.image',
                'c3.description as c3_dep',
                'c1.slug as c1_slug',
                'c2.slug as c2_slug',
                'c3.slug as c3_slug',
            ])
            ->from('categories as c3')
            ->join('categories as c2', 'c3.parent_id', '=', 'c2.id')
            ->join('categories as c1', 'c2.parent_id', '=', 'c1.id')
            ->join('categorizables as cz', 'cz.category_id', '=', 'c3.id')
            ->where('c1.parent_id', null)
            ->where([
                ['c1.category_type', '=', 'product'],
                ['c2.category_type', '=', 'product'],
                ['c3.category_type', '=', 'product'],
                ['c1.status', '=', 1],
                ['c2.status', '=', 1],
                ['c3.status', '=', 1],
                ['cz.categorizable_type', '=', Product::class],
            ])
            ->get();
    }

    public function render()
    {
        return view('bladethemev1::livewire.categories');
    }
}

