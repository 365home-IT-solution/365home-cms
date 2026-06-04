<?php

namespace Modules\Post\App\Filament\Resources\PostResource\Tables;

use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Post\App\Filament\Resources\PostResource\Tables\Actions\PostAction;
use Modules\Post\App\Filament\Resources\PostResource\Tables\BulkAction\PostBulkAction;
use Modules\Post\App\Filament\Resources\PostResource\Tables\Filters\PostFilter;
use Modules\Post\Entities\Post;

class PostTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('media')
                    ->label(__('post::post.table.label.post_image'))
                    ->collection('Ảnh chính'),
                TextColumn::make('title')
                    ->label(__('post::post.table.label.title'))
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->label(__('post::post.table.label.url'))
                    ->getStateUsing(function (Post $record): string {
                        return url("/bai-viet/{$record->slug}");
                    })
                    ->copyable()
                    ->wrap()
                    ->openUrlInNewTab(),
                SpatieTagsColumn::make('tags')
                    ->label(__('post::post.table.label.tags'))
                    ->type('categories')
                    ->colors(['secondary']),
                TextColumn::make('categories.name')
                    ->label(__('post::post.table.label.categories'))
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label(__('post::post.table.label.user'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('post::post.table.label.status'))
                    ->badge()
                    ->getStateUsing(function (Post $record): string {
                        if ($record->status === 'archived') {
                            return 'archived';
                        }
                        if ($record->status === 'draft') {
                            return 'draft';
                        }
                        if ($record->status === 'published') {
                            if (empty($record->published_at)) {
                                return 'draft';
                            }
                            if ($record->published_at->isFuture()) {
                                return 'scheduled';
                            }
                            return 'published';
                        }
                        return 'draft';
                    })
                    ->formatStateUsing(fn(string $state): string => __("post::post.table.status.{$state}"))
                    ->color(fn(string $state): string => match ($state) {
                        'draft' => __('post::post.table.colors.status.draft'),
                        'published' => __('post::post.table.colors.status.published'),
                        'scheduled' => __('post::post.table.colors.status.scheduled'),
                        'archived' => __('post::post.table.colors.status.archived'),
                    }),
                TextColumn::make('published_at')
                    ->label('Ngày công khai')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(PostFilter::filter())
            ->actions(PostAction::action())
            ->bulkActions(PostBulkAction::bulkActions());
    }
}
