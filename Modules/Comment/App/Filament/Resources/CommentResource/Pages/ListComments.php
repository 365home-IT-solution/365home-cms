<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Comment\App\Filament\Resources\CommentResource\CommentResource;

class ListComments extends ListRecords
{
    protected static string $resource = CommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
