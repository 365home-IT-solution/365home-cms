<?php

declare(strict_types=1);

namespace Modules\Post\App\Filament\Resources\PostResource\Pages;

use Illuminate\Support\Facades\Auth;
use Modules\Post\App\Filament\Resources\PostResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['author_id'] = Auth::id();

        return $data;
    }
}
