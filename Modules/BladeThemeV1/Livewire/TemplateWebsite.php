<?php

namespace Modules\BladeThemeV1\Livewire;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Component;
use Modules\Form\Entities\Form;
use Modules\Product\App\Models\Product;
use Modules\BladeThemeV1\Traits\HandleCalculateTrait;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class TemplateWebsite extends Component
{
    use HandleConfigTrait,
        HandleCalculateTrait;

    public $smColumns;
    public $mdColumns;
    public $lgColumns;
    public $selectedProductName = '';

    public function mount($config): void
    {
        $this->setConfig($config);
        $this->calculateColumns();
        $this->uniqueId = $this->generateUniqueId($this->getConfig('name'));
    }

    public function getTemplateWebsites(): Collection|array
    {
        $query = Product::with(['categories', 'media'])
            ->where([
                'is_activated' => true
            ])
            ->whereHas('categories', function($query) {
                $query->where('status', 1);
            });

        $templateWebsiteIds = array_filter(explode(',', $this->getConfig('template_website')));
        $templateWebsiteCategoryIds = array_filter(explode(',', $this->getConfig('template_website_categories')));

        if (!empty($templateWebsiteCategoryIds)) {
            $query->whereHas('categories', function ($q) use ($templateWebsiteCategoryIds) {
                $q->whereIn('categories.id', $templateWebsiteCategoryIds);
            });
        }

        if (!empty($templateWebsiteIds)) {
            $query->whereIn('id', $templateWebsiteIds);
        }

        return $query->latest()
            ->take($this->getConfig('limit_template_website', 4))
            ->get();
    }

    public function getTemplateWebsiteByCategory()
    {
        $templates = $this->getTemplateWebsites();
        $limitPerCategory = $this->getConfig('limit_per_category', 4);

        $templatesByCategory = [];

        foreach ($templates as $template) {
            foreach ($template->categories as $category) {

                if (!isset($templatesByCategory[$category->id])) {
                    $templatesByCategory[$category->id] = [
                        'category_name' => $category->name,
                        'products' => [],
                    ];
                }

                if (count($templatesByCategory[$category->id]['products']) < $limitPerCategory) {
                    $templatesByCategory[$category->id]['products'][] = $template;
                }
            }
        }

        return $templatesByCategory;
    }


    public function getForm(): Model|Collection|Builder|array|null
    {
        $formId = $this->getConfig('form');

        if (!$formId) {
            return null;
        }

        return Form::with([
            'formFields',
            'formFields.fieldValues',
            'submissions',
            'emailSetting',
            'notification'
        ])->find($formId);
    }

    public function calculateColumns(): void
    {
        $columns = $this->calculateColumnsTrait($this->config, $this->getConfig('column', 4));
        $this->smColumns = $columns['sm'];
        $this->mdColumns = $columns['md'];
        $this->lgColumns = $columns['lg'];
    }

    public function render(): View
    {
        $show_template_filter = $this->getConfig('show_template_filter');
        $template_websites = $this->getTemplateWebsiteByCategory();
        if ($show_template_filter) {
        } else {
            $template_websites = $this->getTemplateWebsites();
        }

        $more_product_link = $this->getConfig('more_product_link', '/');
        $form = $this->getForm();
        $more_product_button = $this->getConfig('more_product_button');
        return view('bladethemev1::livewire.template-website', [
            'more_product_link' => $more_product_link,
            'form' => $form,
            'more_product_button' => $more_product_button,
            'template_websites' => $template_websites,
            'show_template_filter' => $show_template_filter
        ]);
    }
}
