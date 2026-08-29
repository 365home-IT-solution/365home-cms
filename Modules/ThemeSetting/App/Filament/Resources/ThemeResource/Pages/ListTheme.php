<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\Pages;

use Modules\ThemeSetting\App\Filament\Resources\ThemeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTheme extends ListRecords
{
    protected static string $resource = ThemeResource::class;

    // Filament KHÔNG tự chặn trang danh sách theo canViewAny() (chỉ ẩn menu) — resource này bị ẩn
    // hẳn khỏi admin nên phải tự chặn truy cập trực tiếp bằng URL ở đây.
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
