<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Book\App\Filament\Resources\PriceBoardResource;

class ListPriceBoards extends ListRecords
{
    protected static string $resource = PriceBoardResource::class;

    // Filament KHÔNG tự chặn trang danh sách theo canViewAny() (chỉ ẩn menu) — phải tự chặn truy cập
    // trực tiếp bằng URL ở đây để giới hạn đúng super_admin.
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
