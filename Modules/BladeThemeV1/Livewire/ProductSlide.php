<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Form\Entities\Form;
use Modules\Product\App\Models\Product;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\BladeThemeV1\Traits\HandleCalculateTrait;
use Modules\BladeThemeV1\Traits\HandleColorTrait;

class ProductSlide extends Component
{
    use HandleConfigTrait, HandleColorTrait, HandleCalculateTrait;

    public string $primaryColor;
    public $primaryColorRgb;
    public string $selectedPlanName = '';

    public function mount($config)
    {
        $this->setConfig($config);
        $this->uniqueId = $this->generateUniqueId('swiper');
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $this->primaryColorRgb = $this->hexToRgb($this->primaryColor);
    }

    public function calculateColumns($default = 4)
    {
        return $this->calculateColumnsTrait($this->config, $default);
    }

    public function fetchProducts()
    {
        $query = Product::with(['media', 'categories'])
            ->where([
                'type' => 'simple',
                'is_activated' => true
            ]);

        $products = $this->getConfig('products');
        if ($products) {
            $productsIds = explode(',', $products);
            if (is_array($productsIds) && !empty($productsIds)) {
                $query->whereIn('id', $productsIds);
            }
        }

        $category = $this->getConfig('category');
        if ($category) {
            $categoryIds = explode(',', $category);
            if (is_array($categoryIds) && !empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }
        }

        return $query->latest()
            ->when($this->getConfig('limit_product'), function ($q) {
                return $q->take($this->getConfig('limit_product'));
            })
            ->get();
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

    public function getBreakpointConfig()
    {
        return [
            'space_between' => $this->getConfig('space_between', 20),
            'autoplay_speed' => $this->getConfig('autoplay_speed', 3000),
            'show_pagination' => $this->getConfig('show_pagination', false),
            'show_navigation' => $this->getConfig('show_navigation', false),
            'style' => $this->getConfig('style', 'default'),
        ];
    }

    public function render()
    {
        $products = $this->fetchProducts();
        $form = $this->fetchForm();
        $breakpointConfig = $this->getBreakpointConfig();

        return view('bladethemev1::livewire.product-slide', [
            'products' => $products,
            'form' => $form,
            'breakpointConfig' => $breakpointConfig,
        ]);
    }
}
