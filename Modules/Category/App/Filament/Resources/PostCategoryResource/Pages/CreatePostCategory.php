<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\PostCategoryResource\Pages;

use Modules\Category\App\Filament\Resources\PostCategoryResource;
use Filament\Resources\Pages\CreateRecord;

// Không đăng ký route 'create' ở PostCategoryResource::getPages() — việc tạo mới diễn ra qua
// Actions\CreateAction (modal) ở ListPostCategory.php, không đi qua page này.
class CreatePostCategory extends CreateRecord
{
    protected static string $resource = PostCategoryResource::class;
}
