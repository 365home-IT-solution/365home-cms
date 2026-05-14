<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Form\Entities\Form;
use Modules\Product\App\Models\Product;
use Modules\Category\Entities\Category;
use Modules\BladeThemeV1\Traits\HandleCalculateTrait;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class ProductGrid extends Component
{
    use HandleColorTrait, HandleCalculateTrait, HandleConfigTrait;

    public $primaryColor;
    public $primaryColorRgb;
    public $selectedPlanName = '';
    public $smColumns;
    public $mdColumns;
    public $lgColumns;

    public function mount($config)
    {
        $this->setConfig($config);
        $this->uniqueId = $this->generateUniqueId($this->getConfig('name'));
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $this->primaryColorRgb = $this->hexToRgb($this->primaryColor);
    }

    public function fetchProducts()
    {
        $query = Product::with(['media', 'categories'])
            ->where([
                'type' => 'simple',
                'is_activated' => true
            ])->whereHas('categories', function ($query) {
                $query->where('status', 1);
            });

        $productsIds = $this->getConfig('products');
        if ($productsIds) {
            $productsIds = explode(',', $productsIds);
            if (is_array($productsIds) && !empty($productsIds)) {
                $query->whereIn('id', $productsIds);
            }
        }

        $categoryIds = $this->getConfig('category');
        if ($categoryIds) {
            $categoryIds = explode(',', $categoryIds);
            if (is_array($categoryIds) && !empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }
        }

        return $query->latest()
            ->when($this->getConfig('limit_product', null), function ($q) {
                return $q->take($this->getConfig('limit_product', 10));
            })
            ->get();
    }

    public function fetchForm()
    {
        $formId = $this->getConfig('form_consulting_product', null);
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

    public function getAlbums()
    {
        $albums = $this->getConfig('albums');
        return $this->transformAlbumsData($albums);
    }

    public function getBanners()
    {
        $banners = $this->getConfig('banners');
        return $this->transformBannerData($banners);
    }

    public function getContactGroups()
    {
        $contactGroups = $this->getConfig('contact_groups');
        return $this->transfromContactGroupData($contactGroups);
    }

    public function fetchCategoryByProduct()
    {
        $categoryIds = $this->getConfig('category');
        if ($categoryIds) {
            $categoryIds = explode(',', $categoryIds);
        } else {
            $categoryIds = [];
        }

        $categories = Category::whereIn('id', $categoryIds)
            ->with(['products' => function ($query) {
                $query->where([
                    'type' => 'simple',
                    'is_activated' => true
                ]);
            }])->get();

        $productsByCategory = [];
        foreach ($categories as $category) {
            $productsByCategory[$category->id]['category_name'] = $category->name;
            $productsByCategory[$category->id]['products'] = $category->products;
        }

        return $productsByCategory;
    }


    public function render()
    {
        $products = [];
        if ($this->getConfig('show_category_filter')) {
            $products = $this->fetchCategoryByProduct();
        } else {
            $products = $this->fetchProducts();
        }

        $form = $this->fetchForm();
        $albums = $this->getAlbums();
        $banners = $this->getBanners();
        $contactGroups = $this->getContactGroups();
        $column = $this->getConfig('column', 4);

        return view('bladethemev1::livewire.product-grid', [
            'products' => $products,
            'form' => $form,
            'albums' => $albums,
            'column' => $column,
            'banners' => $banners,
            'contactGroups' => $contactGroups,
            'show_category_filter' => $this->getConfig('show_category_filter')
        ]);
    }
}
