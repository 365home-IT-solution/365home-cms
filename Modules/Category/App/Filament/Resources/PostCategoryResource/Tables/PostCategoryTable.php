<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\PostCategoryResource\Tables;

use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Modules\Category\App\Filament\Resources\PostCategoryResource\Tables\Actions\PostCategoryAction;
use Modules\Category\App\Filament\Resources\PostCategoryResource\Tables\BulkActions\PostCategoryBulkAction;
use Modules\Category\App\Filament\Resources\PostCategoryResource\Tables\Filters\PostCategoryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PostCategoryTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Hình ảnh')
                    ->sortable()
                    ->defaultImageUrl(Storage::url('no-image.jpg')),

                TextColumn::make('name')
                    ->label('Tên danh mục')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function ($state, $record) {
                        $depth = $record->depth ?? 0;

                        $prefix = '';

                        if ($depth > 0) {
                            $prefix .= str_repeat('— ', $depth);
                        }

                        return new HtmlString($prefix . "<span>$state</span>");
                    }),
                TextColumn::make('parent.name')
                    ->label('Danh mục cha/con')
                    ->sortable()
                    ->getStateUsing(function ($record) {
                        return $record->parent ? $record->parent->name : 'Danh mục gốc';
                    }),
                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('status')
                    ->label('Trạng thái')
                    ->tooltip(function ($record) {
                        return $record->status ? 'Hiển thị' : 'Ẩn';
                    })
                    ->onIcon('heroicon-o-eye')
                    ->offIcon('heroicon-o-eye-slash')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->filters(PostCategoryFilter::filter())
            ->actions(PostCategoryAction::action())
            ->bulkActions(PostCategoryBulkAction::bulkActions())
            ->modifyQueryUsing(function (Builder $query) {
                return $query->select('categories.*')
                    ->selectRaw('
                        (
                            WITH RECURSIVE category_tree(id, path, depth) AS (
                                SELECT id, CAST(CONCAT(LPAD(sort_order, 10, "0"), "-", LPAD(id, 10, "0")) AS CHAR(200)), 0
                                FROM cms_categories
                                WHERE parent_id IS NULL
                                UNION ALL
                                SELECT c.id, CONCAT(ct.path, ",", LPAD(c.sort_order, 10, "0"), "-", LPAD(c.id, 10, "0")), ct.depth + 1
                                FROM cms_categories c
                                JOIN category_tree ct ON c.parent_id = ct.id
                            )
                            SELECT path FROM category_tree WHERE id = cms_categories.id
                        ) as tree_path,
                        (
                            WITH RECURSIVE category_tree(id, depth) AS (
                                SELECT id, 0
                                FROM cms_categories
                                WHERE parent_id IS NULL
                                UNION ALL
                                SELECT c.id, ct.depth + 1
                                FROM cms_categories c
                                JOIN category_tree ct ON c.parent_id = ct.id
                            )
                            SELECT depth FROM category_tree WHERE id = cms_categories.id
                        ) as depth,
                        (
                            SELECT COUNT(*) = 0
                            FROM cms_categories as c
                            WHERE c.parent_id = cms_categories.parent_id AND c.id > cms_categories.id
                        ) as is_last_child,
                        (
                            SELECT COUNT(*) > 0
                            FROM cms_categories as c
                            WHERE c.parent_id = cms_categories.id
                        ) as has_children
                    ')
                    ->orderByRaw('tree_path');
            });
    }
}
