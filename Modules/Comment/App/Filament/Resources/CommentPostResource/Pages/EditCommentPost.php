<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentPostResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Comment\App\Filament\Resources\CommentPostResource\CommentPostResource;

class EditCommentPost extends EditRecord
{
    protected static string $resource = CommentPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\Action::make('Quay lại danh sách')
                ->button()
                ->url(CommentPostResource::getUrl('index'))
        ];
    }
}
