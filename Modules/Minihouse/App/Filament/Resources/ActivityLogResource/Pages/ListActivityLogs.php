<?php

namespace Modules\Minihouse\App\Filament\Resources\ActivityLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\ActivityLogResource;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    // Không có nút "Tạo mới" — nhật ký chỉ tự sinh qua LogsMinihouseActivity, không tạo tay được.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
