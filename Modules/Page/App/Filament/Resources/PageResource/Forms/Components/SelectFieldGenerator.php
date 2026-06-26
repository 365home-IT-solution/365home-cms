<?php

namespace Modules\Page\App\Filament\Resources\PageResource\Forms\Components;

use Filament\Forms\Components\Select;
use Modules\Category\Entities\Category;
use Modules\Form\Entities\Form;
use Modules\Post\Entities\Post;
use Modules\Pricing\Entities\PricingGroup;
use Modules\Pricing\Entities\PricingType;
use Modules\Process\Entities\Process;
use Modules\Product\App\Models\Product;
use Modules\QA\App\Models\QA;

class SelectFieldGenerator
{
    public function createTemplateWebsiteCategorySelectionField($config): Select
    {
        return Select::make("config_values.{$config->id}")
            ->label($config->label)
            ->options(function () {
                $categories = Category::whereHas('products', function ($query) {
                    $query->where([
                        'is_activated' => true,
                        'type' => 'service'
                    ]);
                })->get();

                return $categories->pluck('name', 'id');
            })
            ->extraAttributes([
                'class' => 'relative !overflow-visible'
            ])
            ->columnSpanFull()
            ->placeholder("Chọn " . strtolower($config->label) . " ...")
            ->multiple();
    }

    public function createTemplateWebsiteSelectionField($config): Select
    {
        return Select::make("config_values.{$config->id}")
            ->label($config->label)
            ->options(function () {
                return Product::where([
                    'is_activated' => true,
                    'type' => 'service'
                ])->pluck('name', 'id');
            })
            ->extraAttributes([
                'class' => 'relative !overflow-visible'
            ])
            ->columnSpanFull()
            ->placeholder("Chọn " . strtolower($config->label) . " ...")
            ->multiple();
    }

    public function createSelectFieldWithOptions($config): Select
    {
        return Select::make("config_values.{$config->id}")
            ->label($config->label)
            ->options(function () use ($config) {
                return $config->options->pluck('option_label', 'option_value');
            })
            ->extraAttributes([
                'class' => 'relative !overflow-visible'
            ])
            ->placeholder("Chọn " . strtolower($config->label) . " ...");
    }

    public function createFormSelectionField($config): Select
    {
        return Select::make("config_values.{$config->id}")
            ->label($config->label)
            ->options(function () {
                return Form::query()->pluck('name', 'id');
            })
            ->extraAttributes([
                'class' => 'relative !overflow-visible'
            ])
            ->placeholder("Chọn " . strtolower($config->label) . " ...");
    }

    public function createProductSelectionField($config): Select
    {
        return Select::make("config_values.{$config->id}")
            ->label($config->label)
            ->options(function () {
                return Product::where([
                    'is_activated' => true,
                    'type' => 'simple'
                ])->pluck('name', 'id');
            })
            ->extraAttributes([
                'class' => 'relative !overflow-visible'
            ])
            ->columnSpanFull()
            ->placeholder("Chọn " . strtolower($config->label) . " ...")
            ->multiple();
    }

    public function createPostSelectionField($config): Select
    {
        $isMultiple = $config->type_field !== 'post_selection_one';

        $field = Select::make("config_values.{$config->id}")
            ->label($config->label)
            ->options(function () {
                return Post::query()
                    ->where(['status' => 'published'])
                    ->pluck('title', 'id');
            })
            ->extraAttributes([
                'class' => 'relative !overflow-visible'
            ])
            ->placeholder("Chọn " . strtolower($config->label) . " ...");

        return $isMultiple ? $field->multiple()->columnSpanFull() : $field;
    }

    public function createCategorySelectionField($config): Select
    {
        $options = match ($config->type_field) {
            'category_selection_product' => (function () {
                $categories = Category::with('parent')
                    ->whereHas('products', function ($query) {
                        $query->where([
                            'is_activated' => true,
                            'type' => 'simple'
                        ]);
                    })
                    ->get();

                // Hàm đệ quy để lấy tên danh mục phân cấp
                $getFullName = function ($category) use (&$getFullName) {
                    if ($category->parent) {
                        return $getFullName($category->parent) . ' - ' . $category->name;
                    }
                    return $category->name;
                };

                return $categories->mapWithKeys(function ($category) use ($getFullName) {
                    return [$category->id => $getFullName($category)];
                });
            }),
            'destination' => (function () {
                $categories = Category::whereNull('parent_id')
                    ->where('category_type', 'product')
                    ->get();
                return $categories->pluck('name', 'id');
            }),
            'area' => (function () {
                $getFullName = function ($category) use (&$getFullName) {
                    $category->loadMissing('parent');
                    if ($category->parent) {
                        return $getFullName($category->parent) . ' - ' . $category->name;
                    }
                    return $category->name;
                };

                // L1: danh mục gốc loại product (chi nhánh)
                $destinationIds = Category::whereNull('parent_id')
                    ->where('category_type', 'product')
                    ->pluck('id');

                if ($destinationIds->isEmpty()) {
                    return collect();
                }

                // L2: con trực tiếp của L1
                $level2Categories = Category::with('parent')
                    ->whereIn('parent_id', $destinationIds)
                    ->orderBy('name')
                    ->get();

                // L3: cháu của L1 (con của L2)
                $level2Ids = $level2Categories->pluck('id');
                $level3Categories = $level2Ids->isNotEmpty()
                    ? Category::with('parent.parent')
                        ->whereIn('parent_id', $level2Ids)
                        ->orderBy('name')
                        ->get()
                    : collect();

                return $level2Categories->merge($level3Categories)
                    ->mapWithKeys(fn ($cat) => [$cat->id => $getFullName($cat)]);
            }),
            'category_selection_post' => Category::where('status', 1)
                ->where('category_type', 'post')
                ->pluck('name', 'id'),
            'category_selection_process_design' => Process::pluck('name', 'id'),
            default => collect(),
        };

        $field = Select::make("config_values.{$config->id}")
            ->label($config->label)
            ->options($options)
            ->extraAttributes([
                'class' => 'relative !overflow-visible'
            ])
            ->placeholder("Chọn " . strtolower($config->label) . " ...");

        return match ($config->type_field) {
            'category_selection_process_design', 'destination', 'area' => $field,
            default => $field->multiple()->columnSpanFull(),
        };
    }

    public function createPricingSelectionField($config): Select
    {
        $isGroup = $config->type_field === 'pricing_group_selection';

        $field = Select::make("config_values.{$config->id}")
            ->label($config->label)
            ->options(function () use ($isGroup) {
                return $isGroup
                    ? PricingGroup::query()->pluck('name', 'id')
                    : PricingType::query()->pluck('name', 'id');
            })
            ->extraAttributes([
                'class' => 'relative !overflow-visible'
            ])
            ->placeholder("Chọn " . strtolower($config->label) . " ...");

        return $isGroup ? $field : $field->multiple()->columnSpanFull();
    }

    public function createDomainSelectionField($config): Select
    {
        return Select::make("config_values.{$config->id}")
            ->label($config->label)
            ->options([
                // Phổ biến
                '.vn' => 'Tên miền Việt Nam .vn',
                '.com.vn' => 'Tên miền Việt Nam .com.vn',
                '.net.vn' => 'Tên miền Việt Nam .net.vn',
                '.com' => 'Tên miền quốc tế .com',
                '.net' => 'Tên miền quốc tế .net',
                // ... thêm các option khác
            ])
            ->extraAttributes([
                'class' => 'relative !overflow-visible'
            ])
            ->placeholder("Chọn " . strtolower($config->label) . " ...")
            ->multiple();
    }

    public function createFAQField($config): Select
{
    $isGroup = $config->type_field === 'faq';
    $field = Select::make("config_values.{$config->id}")
        ->label($config->label)
        ->options(function () use ($isGroup) {
            return $isGroup
                    ? QA::query()->where('status', 'published')->pluck('name', 'id') : '';
        })
        ->extraAttributes([
            'class' => 'relative !overflow-visible'
        ])
        // ->searchable()
        ->preload()
        ->required()
        ->placeholder("Chọn " . strtolower($config->label) . " ...")
        ->helperText(function () use ($isGroup) {
            return $isGroup
                ? "Chọn nhóm FAQ để hiển thị"
                : "Chọn các câu hỏi thường gặp để hiển thị";
        });

    return $isGroup ? $field : $field->multiple()->columnSpanFull();
}
}
