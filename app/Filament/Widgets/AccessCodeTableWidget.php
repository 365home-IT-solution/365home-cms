<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\AccessCodeTable;

// Bảng "Pass Cổng" bên trong trang gộp "Khóa cổng" (App\Filament\Pages\GateLockManagement) — tái
// dùng NGUYÊN VẸN cột/filter/action/quyền hạn đã có ở AccessCodeResource, chỉ đổi nơi hiển thị
// (AccessCodeResource vẫn còn route thật, chỉ ẩn khỏi menu — xem shouldRegisterNavigation() ở đó).
class AccessCodeTableWidget extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view_any_access::code') ?? false;
    }

    protected function getTableQuery(): Builder
    {
        return AccessCodeResource::getEloquentQuery();
    }

    public function table(Table $table): Table
    {
        return AccessCodeTable::table($table)->heading('Pass Cổng');
    }
}
