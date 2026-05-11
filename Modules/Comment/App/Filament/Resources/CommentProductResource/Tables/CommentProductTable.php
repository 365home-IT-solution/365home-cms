<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentProductResource\Tables;

use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Support\Colors\Color;
use Modules\Comment\App\Filament\Resources\CommentPostResource\Tables\Actions\CommentProductAction;
use Modules\Comment\App\Filament\Resources\CommentProductResource\CommentProductResource;
use Modules\Comment\App\Filament\Resources\CommentProductResource\Tables\Filters\CommentProductFilter;
use Modules\Comment\Entities\Comment;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\ToggleColumn;

class CommentProductTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->query(
                Comment::query()->where('commentable_type', __('comment::comment-product.table.query.commentable_type'))
                    ->whereNotNull('commentable_id')
            )
            ->columns([
                TextColumn::make('commentable.name')
                    ->label('Sản phẩm')
                    ->wrap()
                    ->limit(50)
                    ->icon('heroicon-m-link')
                    ->iconPosition(IconPosition::Before)
                    ->alignCenter()
                    ->url(fn($record) => $record->commentable
                        ? config('app.domain') . ($record->commentable->slug
                            ? "/san-pham/" . $record->commentable->slug
                            : "/mon-an/" . $record->commentable->slug)
                        : null)
                    ->openUrlInNewTab()
                    ->state(fn($record) => $record->commentable && $record->commentable->name
                                            ? $record->commentable->name
                                            : 'Sản phẩm này đã bị xóa')
                    ->color(Color::Blue)
                    ->searchable(
                        query: function ($query, $search) {
                            return $query->whereHasMorph('commentable', ['Modules\Product\App\Models\Product'], function ($q) use ($search) {
                                $q->where('name', 'like', "%{$search}%");
                            });
                        }
                    )
                    ->sortable(
                        query: function ($query, $direction) {
                            return $query->whereHasMorph('commentable', ['Modules\Product\App\Models\Product'], function ($q) use ($direction) {
                                $q->orderBy('name', $direction);
                            });
                        }
                    ),
                TextColumn::make('name')
                    ->label(__('comment::comment-product.table.label.name'))
                    ->alignCenter()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('text')
                    ->label(__('comment::comment-product.table.label.text'))
                    ->wrap()
                    ->limit(50)
                    ->alignCenter()
                    ->searchable()
                    ->sortable(),
                ToggleColumn::make('show')
                    ->label(__('comment::comment-product.table.label.show'))
                    ->tooltip(function ($record) {
                        return $record->show ? 'Hiển thị' : 'Ẩn';
                    })
                    ->onIcon(__('comment::comment-product.table.icons.showOn'))
                    ->offIcon(__('comment::comment-product.table.icons.showOff'))
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('replies_count')->counts('replies')
                    ->label(__('comment::comment-product.table.label.replies_count'))
                    ->wrap()
                    ->badge()
                    ->color('warning')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('comment::comment-product.table.label.created_at'))
                    ->date('d/m/Y')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->emptyStateActions([
                Action::make('create')
                    ->label(__('comment::comment-product.table.actions.create'))
                    ->url(CommentProductResource::getUrl('create'))
                    ->icon(__('comment::comment-product.table.icons.plus'))
                    ->button(),
            ])
            ->defaultSort(__('comment::comment-product.table.defaultSort.columnToSort'), __('comment::comment-product.table.defaultSort.chooseSort'))
            ->filters(CommentProductFilter::fillter())
            ->actions(CommentProductAction::action())
            ->bulkActions(CommentProductAction::bulkActions());
    }
}
