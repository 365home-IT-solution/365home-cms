<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentPostResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Comment\App\Filament\Resources\CommentPostResource\CommentPostResource;

class CreateCommentPost extends CreateRecord
{
    protected static string $resource = CommentPostResource::class;
}
