<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Modules\Product\App\Filament\Resources\ManualLockPasswordResource;
use Modules\Product\App\Models\ManualLockPassword;

// Bảng "Khóa thủ công" bên trong trang gộp "Khóa cổng" (App\Filament\Pages\GateLockManagement) —
// tái dùng NGUYÊN VẸN cột/filter/action/quyền hạn đã có ở ManualLockPasswordResource, chỉ đổi nơi
// hiển thị (resource vẫn còn route thật, chỉ ẩn khỏi menu — xem shouldRegisterNavigation() ở đó).
class ManualLockPasswordTableWidget extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', ManualLockPassword::class) ?? false;
    }

    protected function getTableQuery(): Builder
    {
        return ManualLockPasswordResource::getEloquentQuery();
    }

    public function table(Table $table): Table
    {
        return ManualLockPasswordResource::table($table)->heading('Khóa thủ công');
    }
}
