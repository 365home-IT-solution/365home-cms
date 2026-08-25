<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Category\Entities\Category;

class CategoryFilter
{
    public static function filter(): array
    {
        return [
            Filter::make('created_at')
                ->label(__('category::category.filter.label.created_at'))
                ->form([
                    DatePicker::make('created_from')
                        ->label(__('category::category.filter.label.created_from')),
                    DatePicker::make('created_until')
                        ->label(__('category::category.filter.label.created_until')),
                ])
                ->query(function ($query, array $data) {
                    if ($data['created_from']) {
                        $query->whereDate('created_at', '>=', $data['created_from']);
                    }

                    if ($data['created_until']) {
                        $query->whereDate('created_at', '<=', $data['created_until']);
                    }

                    return $query;
                }),
            SelectFilter::make('status')
                ->label(__('category::category.filter.label.status'))
                ->options([
                    '1' => __('category::category.table.options.active'),
                    '0' => __('category::category.table.options.inactive')
                ])
                ->query(function ($query, $data) {
                    if (!isset($data['value']) || $data['value'] === '') {
                        return $query;
                    }
                    return $query->where('status', $data['value']);
                }),
            SelectFilter::make('parent_id')
                ->label(__('category::category.filter.label.parent'))
                ->options(function () {
                    $user  = auth()->user();
                    $query = Category::select('categories.*')
                        ->selectRaw('
                            (
                                WITH RECURSIVE category_tree(id, path, depth) AS (
                                    SELECT id, CAST(name AS CHAR(200)), 0
                                    FROM cms_categories
                                    WHERE parent_id IS NULL
                                    UNION ALL
                                    SELECT c.id, CONCAT(ct.path, " > ", c.name), ct.depth + 1
                                    FROM cms_categories c
                                    JOIN category_tree ct ON c.parent_id = ct.id
                                )
                                SELECT path FROM category_tree WHERE id = cms_categories.id
                            ) as hierarchical_name,
                            (
                                SELECT COUNT(*) = 0
                                FROM cms_categories as child
                                WHERE child.parent_id = cms_categories.id
                            ) as is_last_child
                        ');

                    // Lọc theo đối tác (+ thu hẹp thêm theo quyền chi nhánh cụ thể nếu có)
                    if ($user && ! $user->isSuperAdmin()) {
                        $allowedBranchIds      = $user->allowedBranchIds();
                        $allowedPostCategoryIds = $user->allowedPostCategoryIds();

                        $query->where(function ($q) use ($user, $allowedBranchIds, $allowedPostCategoryIds) {
                            $q->where('partner_id', $user->partner_id);

                            if (! empty($allowedBranchIds)) {
                                $q->whereIn('id', $allowedBranchIds)
                                  ->orWhereIn('parent_id', $allowedBranchIds);
                            }

                            if (! empty($allowedPostCategoryIds)) {
                                $q->orWhereIn('id', $allowedPostCategoryIds)
                                  ->orWhereIn('parent_id', $allowedPostCategoryIds);
                            }
                        });
                    }

                    $categories = $query->get();

                    $options = [];
                    foreach ($categories as $category) {
                        $prefix = '';
                        if ($category->parent_id) {
                            $prefix = str_repeat('— ', substr_count($category->hierarchical_name, '>'));
                        }

                        $options[$category->id] = $prefix . $category->name;
                    }

                    return $options;
                })
                ->query(function ($query, $data) {
                    if (!isset($data['value']) || $data['value'] === '') {
                        return $query;
                    }
                    
                    $category = Category::find($data['value']);
                    if ($category) {
                        if ($category->parent_id === null) {
                            // Nếu là danh mục cha, lấy tất cả con của nó
                            return $query->where(function($q) use ($category) {
                                $q->where('id', $category->id)
                                  ->orWhere('parent_id', $category->id);
                            });
                        } else {
                            // Nếu là danh mục con, chỉ lấy chính nó
                            return $query->where('id', $category->id);
                        }
                    }
                    
                    return $query;
                }),
        ];
    }
}
