<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentPostResource\Pages;

use Modules\Comment\App\Filament\Resources\CommentPostResource\CommentPostResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCommentPost extends ViewRecord
{
    protected static string $resource = CommentPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make('Cập nhật')
                ->button(),
            Actions\Action::make('Quay lại')
                ->button()
                ->url(CommentPostResource::getUrl('index'))
        ];
    }
}
