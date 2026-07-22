<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Pages;

use Modules\Category\App\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

// Lưu ý: CategoryResource::getPages() không đăng ký route 'create' cho page này — việc tạo mới
// thực tế diễn ra qua Actions\CreateAction (modal) ở ListCategory.php, không đi qua page này.
// Logic tự gán partner_id đặt ở ListCategory.php, KHÔNG đặt ở đây.
class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;
}