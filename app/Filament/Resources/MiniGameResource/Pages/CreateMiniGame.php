<?php

declare(strict_types=1);

namespace App\Filament\Resources\MiniGameResource\Pages;

use App\Filament\Resources\MiniGameResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMiniGame extends CreateRecord
{
    protected static string $resource = MiniGameResource::class;

    // Tạo xong vào thẳng màn sửa để thêm lá bài
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
