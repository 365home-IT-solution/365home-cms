<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentPostResource\Tables;

use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Support\Colors\Color;
use Modules\Comment\App\Filament\Resources\CommentPostResource\CommentPostResource;
use Modules\Comment\App\Filament\Resources\CommentPostResource\Tables\Actions\CommentPostAction;
use Modules\Comment\App\Filament\Resources\CommentPostResource\Tables\Filters\CommentPostFilter;
use Modules\Comment\Entities\Comment;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\ToggleColumn;

class CommentPostTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->query(
                Comment::query()->where('commentable_type', __('comment::comment-post.table.query.commentable_type'))
                    ->whereNotNull('commentable_id')
            )
            ->columns([
                TextColumn::make('commentable.title')
                    ->label('Bài viết')
                    ->wrap()
                    ->limit(50)
                    ->icon('heroicon-m-link')
                    ->iconPosition(IconPosition::Before)
                    ->url(fn($record) => $record->commentable 
                        ? config('app.domain') . ($record->commentable->slug 
                            ? "/bai-viet/" . $record->commentable->slug 
                            : "/tin-tuc/" . $record->commentable->slug)
                        : null)
                    ->openUrlInNewTab()
                    ->color(Color::Blue)
                    ->searchable(
                        query: function ($query, $search) {
                            return $query->whereHasMorph('commentable', ['Modules\Post\Entities\Post'], function ($q) use ($search) {
                                $q->where('title', 'like', "%{$search}%");
                            });
                        }
                    )
                    ->sortable(
                        query: function ($query, $direction) {
                            return $query->whereHasMorph('commentable', ['Modules\Post\Entities\Post'], function ($q) use ($direction) {
                                $q->orderBy('title', $direction);
                            });
                        }
                    )
                    ->alignCenter(),
                TextColumn::make('name')
                    ->label(__('comment::comment-post.table.label.name'))
                    ->alignCenter()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('text')
                    ->label(__('comment::comment-post.table.label.text'))
                    ->wrap()
                    ->limit(50)
                    ->alignCenter()
                    ->searchable()
                    ->sortable(),
                ToggleColumn::make('show')
                    ->label(__('comment::comment-post.table.label.show'))
                    ->tooltip(function ($record) {
                        return $record->show
                            ? __('comment::comment-post.table.options.showOn')
                            : __('comment::comment-post.table.options.showOff');
                    })
                    ->onIcon(__('comment::comment-post.table.icons.showOn'))
                    ->offIcon(__('comment::comment-post.table.icons.showOff'))
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('replies_count')->counts('replies')
                    ->label(__('comment::comment-post.table.label.replies_count'))
                    ->wrap()
                    ->badge()
                    ->color('warning')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('comment::comment-post.table.label.created_at'))
                    ->dateTime()
                    ->alignCenter()
                    ->sortable(),
            ])
            ->emptyStateActions([
                Action::make('create')
                    ->label(__('comment::comment-post.table.actions.create'))
                    ->url(CommentPostResource::getUrl('create'))
                    ->icon(__('comment::comment-post.table.icons.plus'))
                    ->button(),
            ])
            ->defaultSort(__('comment::comment-post.table.defaultSort.columnToSort'), __('comment::comment-post.table.defaultSort.chooseSort'))
            ->filters(CommentPostFilter::fillter())
            ->actions(CommentPostAction::action())
            ->bulkActions(CommentPostAction::bulkActions());
    }
}
