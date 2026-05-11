<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Comment\App\Filament\Resources\CommentResource\CommentResource;

class CreateComment extends CreateRecord
{
    protected static string $resource = CommentResource::class;
}
