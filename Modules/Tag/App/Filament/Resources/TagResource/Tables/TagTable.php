<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources\TagResource\Tables;

use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Tag\App\Filament\Resources\TagResource\Tables\Actions\TagAction;
use Modules\Tag\App\Filament\Resources\TagResource\Tables\BulkActions\TagBulkAction;
use Modules\Tag\App\Filament\Resources\TagResource\Tables\Filters\TagFilter;

class TagTable
{
    private static array $typeMap = [
        'Post' => 'Bài viết',
        'Product' => 'Phòng sử dụng'
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label(__('tag::tag.table.label.image'))
                    ->sortable()
                    ->getStateUsing(function ($record) {
                        $image = $record->getRawOriginal('image') ?? $record->image;
                        if (is_array($image)) {
                            $image = $image[app()->getLocale()] ?? collect($image)->first();
                        } elseif (is_string($image) && str_starts_with($image, '{')) {
                            $decoded = json_decode($image, true);
                            if (is_array($decoded)) {
                                $image = $decoded[app()->getLocale()] ?? collect($decoded)->first();
                            }
                        }
                        return $image ?: null;
                    })
                    ->defaultImageUrl(Storage::url('no-image.jpg')),
                TextColumn::make('name')
                    ->label(__('tag::tag.table.label.name'))
                    ->badge()
                    ->color('success')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('usage_count')
                    ->label(__('tag::tag.table.label.usage_count'))
                    ->badge()
                    ->sortable(query: function ($query, $direction) {
                        return $query
                            ->withCount('taggables')
                            ->orderBy('taggables_count', $direction);
                    })
                    ->getStateUsing(function ($record) {
                        return DB::table('taggables')
                            ->where('tag_id', $record->id)
                            ->count();
                    }),
                TextColumn::make('usage_details')
                    ->label(__('tag::tag.table.label.usage_details'))
                    ->getStateUsing(function ($record) {
                        $counts = DB::table('taggables')
                            ->where('tag_id', $record->id)
                            ->select('taggable_type', DB::raw('count(*) as count'))
                            ->groupBy('taggable_type')
                            ->get();

                        return $counts->map(function ($item) {
                            $type = class_basename($item->taggable_type);
                            $displayName = self::$typeMap[$type] ?? $type;
                            return "{$displayName}: {$item->count}";
                        })->join(', ');
                    })
                    ->tooltip(function ($record) {
                        return DB::table('taggables')
                            ->where('tag_id', $record->id)
                            ->select('taggable_type', DB::raw('count(*) as count'))
                            ->groupBy('taggable_type')
                            ->get()
                            ->map(function ($item) {
                                $type = class_basename($item->taggable_type);
                                $displayName = self::$typeMap[$type] ?? $type;
                                return "{$displayName}: {$item->count}";
                            })
                            ->join("\n");
                    }),
                TextColumn::make('created_at')
                    ->label(__('tag::tag.table.label.created_at'))
                    ->dateTime()
                    ->sortable(),

            ])
            ->defaultSort('created_at', 'desc')
            ->filters(TagFilter::filter())
            ->actions(TagAction::action())
            ->bulkActions(TagBulkAction::bulkActions());
    }
}
