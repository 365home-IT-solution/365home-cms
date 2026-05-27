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
                // Hàm đệ quy để lấy tên danh mục phân cấp
                $getFullName = function ($category) use (&$getFullName) {
                    // Tải 'parent' nếu chưa tải (tránh N+1 query)
                    $category->loadMissing('parent');
                    if ($category->parent) {
                        return $getFullName($category->parent) . ' - ' . $category->name;
                    }
                    return $category->name;
                };

                // B1: Lấy ID danh mục cấp 1 (destination)
                // (Giả định 'destination' dùng 'category_type' == 'product' dựa theo case 'destination')
                $destinationIds = Category::whereNull('parent_id')
                    ->where('category_type', 'product')
                    ->pluck('id');

                // B2: Lấy danh mục cấp 2 (Area) thỏa mãn điều kiện
                $level2Categories = Category::with('parent') // Tải parent (L1)
                    ->whereIn('parent_id', $destinationIds)
                    ->where(function ($query) {
                        $query
                            // 1. Có sản phẩm trực tiếp (L2)
                            ->whereHas('products', fn ($q) => $q->where('is_activated', true))
                            // 2. Hoặc có con (L3) có sản phẩm
                            ->orWhereHas('children', fn ($q_l3) =>
                                $q_l3->whereHas('products', fn ($q_p) => $q_p->where('is_activated', true))
                            )
                            // 3. Hoặc có cháu (L4) có sản phẩm
                            ->orWhereHas('children.children', fn ($q_l4) =>
                                $q_l4->whereHas('products', fn ($q_p) => $q_p->where('is_activated', true))
                            );
                    })
                    ->get();

                // B3: Lấy ID của TẤT CẢ danh mục cấp 2 (để tìm con cấp 3 của chúng)
                $allLevel2Ids = Category::whereIn('parent_id', $destinationIds)->pluck('id');

                // B4: Lấy danh mục cấp 3 (Sub-area) thỏa mãn điều kiện
                $level3Categories = Category::with('parent.parent') // Tải parent (L2) và parent.parent (L1)
                    ->whereIn('parent_id', $allLevel2Ids)
                    ->where(function ($query) {
                        $query
                            // 1. Có sản phẩm trực tiếp (L3)
                            ->whereHas('products', fn ($q) => $q->where('is_activated', true))
                            // 2. Hoặc có con (cấp 4) có sản phẩm
                            ->orWhereHas('children', fn ($q1) =>
                            $q1->whereHas('products', fn ($q2) =>
                            $q2->where('is_activated', true)
                            )
                            );
                    })
                    ->get();

                // B5: Gộp cả hai danh sách cấp 2 và cấp 3
                $allValidCategories = $level2Categories->merge($level3Categories);

                // B6: Tạo options với tên phân cấp đầy đủ
                return $allValidCategories->mapWithKeys(function ($category) use ($getFullName) {
                    return [$category->id => $getFullName($category)];
                });
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
