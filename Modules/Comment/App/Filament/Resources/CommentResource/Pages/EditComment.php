<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Comment\App\Filament\Resources\CommentResource\CommentResource;

class EditComment extends EditRecord
{
    protected static string $resource = CommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
