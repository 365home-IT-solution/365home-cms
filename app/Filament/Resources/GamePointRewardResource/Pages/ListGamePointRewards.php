<?php

declare(strict_types=1);

namespace App\Filament\Resources\GamePointRewardResource\Pages;

use App\Filament\Resources\GamePointRewardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGamePointRewards extends ListRecords
{
    protected static string $resource = GamePointRewardResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm quà')];
    }
}
