<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Page\Entities\ComponentConfiguration;
use Modules\Form\Entities\Form;
use Modules\Product\App\Models\Product;
use Modules\ThemeSetting\App\Models\ThemeSection;
use Modules\BladeThemeV1\Enums\HeaderSection;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\BladeThemeV1\Traits\HandleSectionCfgTrait;

class TemplateDetail extends Component
{
    use HandleConfigTrait, HandleSectionCfgTrait;

    public $slug;
    public $product;
    public $relatedProducts;
    public $contacts;
    public $selectedProductName;
    public $formErrorMessage = '';

    private readonly ThemeSection $section;
    public array $header_contacts = [];

    public array $contactIcons = [
        'phone' => 'heroicon-o-phone',
        'email' => 'heroicon-o-envelope',
        'address' => 'heroicon-o-map-pin',
        'hotline' => 'heroicon-o-phone-arrow-up-right',
        'working_hours' => 'heroicon-o-clock',
        'website' => 'heroicon-o-globe-alt',
    ];


    public function mount($slug)
    {
        $this->slug = $slug;
        $this->product = $this->getProduct();
        $this->relatedProducts = $this->getRelatedPrducts();
        $this->section = $this->getHeaderConfigs();
        $this->header_contacts = $this->getChildSectionConfigs(HeaderSection::TOP_BAR->value);
        $this->contacts = $this->fetchContacts();
    }

    public function fetchForm()
    {
        $compConfig = ComponentConfiguration::with(['component', 'pageComponentConfigurationValues' => function ($query) {
            $query->select('comp_page_values.value', 'comp_page_values.type', 'comp_pages.created_at')
                ->latest()
                ->limit(1);
        }])
            ->where('name', 'form_consulting_product')
            ->whereHas('component', function($query) {
                $query->where('name', 'product');
            })
            ->first();

        if (!$compConfig || $compConfig->pageComponentConfigurationValues->isEmpty()) {
            $this->formErrorMessage = 'Biểu mẫu chưa được tạo hoặc đã bị tắt.';
            return null;
        }

        if ($compConfig && $compConfig->pageComponentConfigurationValues->isNotEmpty()) {
            $latestValue = $compConfig->pageComponentConfigurationValues->first();
            $formId = $latestValue->pivot->value;
        }

        if (!empty($formId)) {
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

    public function getProduct()
    {
        $query = Product::with(['categories', 'media'])
            ->where([
                'is_activated' => true,
                'type' => 'service'
            ]);

        return $query->where('slug', $this->slug)->firstOrFail();
    }

    public function fetchContacts()
    {
        $contactLabels = [
            'phone' => 'Số điện thoại',
            'email' => 'Email',
            'address' => 'Địa chỉ',
            'hotline' => 'Hotline',
            'working_hours' => 'Giờ làm việc',
            'website' => 'Website',
        ];

        return collect($this->header_contacts['header_contacts'])->map(function ($contact) use ($contactLabels) {
            return array_merge($contact, [
                'contact_key' => $contact['contact_key'] ?? $contactLabels[$contact['contact_type']] ?? 'Liên hệ'
            ]);
        })->toArray();
    }

    private function getHeaderConfigs()
    {
        return ThemeSection::where('name', 'header')
            ->with(['children'])
            ->first();
    }


    public function getRelatedPrducts()
    {
        $query = Product::with(['categories', 'media'])
            ->where([
                'is_activated' => true,
                'type' => 'service'
            ])
            ->where('id', '!=', $this->product->id);

        $categoryIds = $this->product->categories->pluck('id')->toArray();
        $query->whereHas('categories', function ($q) use ($categoryIds) {
            $q->whereIn('categories.id', $categoryIds);
        });

        return $query->latest()->limit(8)->get();
    }

    public function render()
    {
        $url = url()->current();
        $title = $this->product->name;
        return view('bladethemev1::livewire.template-detail', [
            'url' => $url,
            'title' => $title,
            'form' => $this->fetchForm()
        ]);
    }
}
