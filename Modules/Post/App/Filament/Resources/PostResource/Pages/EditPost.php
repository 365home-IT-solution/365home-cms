<?php

declare(strict_types=1);

namespace Modules\Post\App\Filament\Resources\PostResource\Pages;

use Modules\Post\App\Filament\Resources\PostResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['status'] !== 'published') {
            $data['published_at'] = null;
        }

        return $data;
    }

}