<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\PostCategoryResource\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Modules\Category\Entities\Category;

class PostCategoryFilter
{
    public static function filter(): array
    {
        return [
            Filter::make('created_at')
                ->label('Ngày tạo')
                ->form([
                    DatePicker::make('created_from')->label('Từ ngày'),
                    DatePicker::make('created_until')->label('Đến ngày'),
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
                ->label('Lọc theo trạng thái')
                ->options([
                    '1' => 'Hiển thị',
                    '0' => 'Ẩn',
                ])
                ->query(function ($query, $data) {
                    if (!isset($data['value']) || $data['value'] === '') {
                        return $query;
                    }
                    return $query->where('status', $data['value']);
                }),
            SelectFilter::make('parent_id')
                ->label('Lọc theo danh mục cha')
                ->options(function () {
                    $user  = auth()->user();
                    $query = Category::query()->where('category_type', 'post');

                    if ($user && ! $user->isSuperAdmin()) {
                        $allowedPostCategoryIds = $user->allowedPostCategoryIds();

                        if (empty($allowedPostCategoryIds)) {
                            $query->whereRaw('1 = 0');
                        } else {
                            $query->whereIn('id', $allowedPostCategoryIds);
                        }
                    }

                    return $query->get()
                        ->mapWithKeys(fn ($category) => [
                            $category->id => ($category->parent_id ? '— ' : '') . $category->name,
                        ])
                        ->all();
                })
                ->query(function ($query, $data) {
                    if (!isset($data['value']) || $data['value'] === '') {
                        return $query;
                    }

                    $category = Category::find($data['value']);
                    if ($category) {
                        if ($category->parent_id === null) {
                            return $query->where(function ($q) use ($category) {
                                $q->where('id', $category->id)
                                  ->orWhere('parent_id', $category->id);
                            });
                        }

                        return $query->where('id', $category->id);
                    }

                    return $query;
                }),
        ];
    }
}
