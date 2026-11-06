<?php

declare(strict_types=1);

namespace App\Filament\Resources\GamePointRewardResource\Pages;

use App\Filament\Resources\GamePointRewardResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGamePointReward extends CreateRecord
{
    protected static string $resource = GamePointRewardResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
