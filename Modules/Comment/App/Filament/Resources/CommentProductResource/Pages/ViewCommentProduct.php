<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentProductResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Modules\Comment\App\Filament\Resources\CommentProductResource\CommentProductResource;

class ViewCommentProduct extends ViewRecord
{
    protected static string $resource = CommentProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make('Cập nhật')
            ->button(),

            Actions\Action::make('Quay lại')
                ->button()
                ->url(CommentProductResource::getUrl('index'))
        ];
    }
}
