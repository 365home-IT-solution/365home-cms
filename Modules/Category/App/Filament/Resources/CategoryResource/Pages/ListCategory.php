<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Pages;

use Modules\Category\App\Filament\Resources\CategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;

class ListCategory extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth(MaxWidth::Full),
        ];
    }

    public function getTabs(): array
    {
        $baseQuery = static::getResource()::getEloquentQuery();

        return [
            'product' => Tab::make('Phòng')
                ->icon('heroicon-o-home')
                ->badge($baseQuery->clone()->where('category_type', 'product')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('category_type', 'product')),

            'post' => Tab::make('Bài viết')
                ->icon('heroicon-o-document-text')
                ->badge($baseQuery->clone()->where('category_type', 'post')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('category_type', 'post')),
        ];
    }
}
