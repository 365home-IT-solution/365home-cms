<?php

declare(strict_types=1);

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Pages;

use Modules\Themestudio\App\Filament\Resources\ThemeStudioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListThemeStudio extends ListRecords
{
    protected static string $resource = ThemeStudioResource::class;

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
