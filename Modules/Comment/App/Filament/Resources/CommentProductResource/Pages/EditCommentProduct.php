<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentProductResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Comment\App\Filament\Resources\CommentProductResource\CommentProductResource;

class EditCommentProduct extends EditRecord
{
    protected static string $resource = CommentProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\Action::make('Quay lại danh sách')
                ->button()
                ->url(CommentProductResource::getUrl('index'))
        ];
    }
}
