<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentProductResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Comment\App\Filament\Resources\CommentProductResource\CommentProductResource;

class CreateCommentProduct extends CreateRecord
{
    protected static string $resource = CommentProductResource::class;
}
