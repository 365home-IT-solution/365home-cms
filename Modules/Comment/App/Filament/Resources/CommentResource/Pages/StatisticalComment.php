<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Comment\App\Filament\Resources\CommentResource\CommentResource;
use Modules\Comment\App\Filament\Resources\CommentResource\Widgets\TotalCommentProductAndPost;

class StatisticalComment extends ListRecords
{
    protected static string $resource = CommentResource::class;

    protected static string $view = 'comment::filament.resources.comment-resource.pages.statistical-comment';

    protected function getHeaderWidgets(): array
    {
        return [
           TotalCommentProductAndPost::class
        ];
    }
}
