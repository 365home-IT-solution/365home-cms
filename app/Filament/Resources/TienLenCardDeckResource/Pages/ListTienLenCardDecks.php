<?php

declare(strict_types=1);

namespace App\Filament\Resources\TienLenCardDeckResource\Pages;

use App\Filament\Resources\TienLenCardDeckResource;
use App\Filament\Resources\TienLenMatchResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTienLenCardDecks extends ListRecords
{
    protected static string $resource = TienLenCardDeckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('Về Ván chơi')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(fn () => TienLenMatchResource::getUrl('index')),
            CreateAction::make()->label('Thêm bộ bài'),
        ];
    }

    // Breadcrumb: Tiến lên — Ván chơi › Bộ bài
    public function getBreadcrumbs(): array
    {
        return [TienLenMatchResource::getUrl('index') => 'Tiến lên — Ván chơi', 'Bộ bài'];
    }
}
