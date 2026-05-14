<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Form\Entities\Form;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product as ModelsProduct;
use Modules\BladeThemeV1\Traits\HandleCalculateTrait;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Product extends Component
{
    use HandleColorTrait, HandleCalculateTrait, HandleConfigTrait, WithPagination;

    public $primaryColor;
    public $selectedPlanName = '';
    public $perPage = 9;
    public $smColumns;
    public $mdColumns;
    public $lgColumns;
    public $categorySearch = '';
    public $categories = [];
    public ?bool $is_virtual_product = false;

    #[Url(as: 'trang')]
    public $page = 1;

    #[Url(as: 'tim-kiem')]
    public $search = '';

    #[Url(as: 'danh-muc')]
    public $selectedCategory = '';

    #[Url(as: 'sap-xep')]
    public $sortBy = 'newest';

    #[Url(as: 'kieu-hien-thi')]
    public $viewStyle = 'grid';

    public function mount($config)
    {
        $this->setConfig($config);
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $this->calculateColumns();
        $this->is_virtual_product = $this->getConfig('virtual_product') ?? false;
        $this->perPage = $this->getConfig('per_page', $this->perPage);
        $this->categories = $this->fetchCategories();
    }

    public function calculateColumns()
    {
        $columns = $this->calculateColumnsTrait($this->config, 3);
        $this->smColumns = $columns['sm'];
        $this->mdColumns = $columns['md'];
        $this->lgColumns = $columns['lg'];
    }

    public function fetchCategories()
    {
        $query = Category::where('category_type', 'product')
            ->whereNull('parent_id')
            ->where('status', 1)
            ->with(['children' => function ($childQuery) {
                $childQuery->where('status', 1)
                    ->whereHas('products');
            }])
            ->select('id', 'name', 'slug', 'parent_id');

        $query->whereHas('products', function ($subQuery) {
            if ($this->is_virtual_product) {
                $subQuery->where('type', 'service');
            } else {
                $subQuery->where('type', '!=', 'service');
            }
        });

        return $query->get();
    }

    public function getFilteredCategoriesProperty()
    {
        if (empty($this->categorySearch)) {
            return $this->categories;
        }

        return $this->filterCategories($this->categories);
    }

    private function filterCategories($categories)
    {
        return $categories->map(function ($category) {
            $newCategory = clone $category;

            if (stripos($newCategory->name, $this->categorySearch) !== false) {
                if ($newCategory->children->isNotEmpty()) {
                    $newCategory->setRelation('children', $this->filterCategories($newCategory->children));
                }
                return $newCategory;
            }

            if ($newCategory->children->isNotEmpty()) {
                $filteredChildren = $this->filterCategories($newCategory->children);
                if ($filteredChildren->isNotEmpty()) {
                    $newCategory->setRelation('children', $filteredChildren);
                    return $newCategory;
                }
            }

            return null;
        })->filter();
    }

    public function fetchProducts()
    {
        $query = ModelsProduct::with(['media', 'categories'])
            ->where('is_activated', true)->whereHas('categories', function ($query) {
                $query->where('status', 1);
            });

        if (!$this->is_virtual_product) {
            $query->where('type', 'simple');
        } else {
            $query->where('type', 'service');
        }

        if (!empty($this->search)) {
            $query->where('name', 'like', '%' . $this->search . '%');
        }

        if (!empty($this->selectedCategory)) {
            $query->whereHas('categories', function ($q) {
                $q->where('categories.slug', $this->selectedCategory);
            });
        }

        switch ($this->sortBy) {
            case 'A-Z':
                $query->orderBy('name', 'asc');
                break;
            case 'Z-A':
                $query->orderBy('name', 'desc');
                break;
            case 'newest':
            default:
                $query->latest();
                break;
        }

        return $query->paginate($this->perPage);
    }

    #[On('pageChanged')]
    public function updatePage($trang)
    {
        $this->page = $trang;
    }

    public function fetchForm()
    {
        $formId = $this->getConfig('form_consulting_product');
        if ($formId) {
            return Form::with([
                'formFields',
                'formFields.fieldValues',
                'submissions',
                'emailSetting',
                'notification'
            ])->find($formId);
        }
        return null;
    }

    public function setSelectedPlanName($name)
    {
        $this->selectedPlanName = $name;
    }

    public function resetSelectedPlanName()
    {
        $this->selectedPlanName = '';
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingSelectedCategory()
    {
        $this->resetPage();
    }

    public function updatingSortBy()
    {
        $this->resetPage();
    }

    public function setViewStyle($style)
    {
        $this->viewStyle = $style;
    }

    public function render()
    {
        $products = $this->fetchProducts();
        $form = $this->fetchForm();

        return view('bladethemev1::livewire.product', [
            'products' => $products,
            'categories' => $this->categories,
            'filteredCategories' => $this->filteredCategories,
            'form' => $form
        ]);
    }
}
