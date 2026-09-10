<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\ActivityLogResource\Pages;
use Modules\Minihouse\App\Filament\Resources\ActivityLogResource\Tables\ActivityLogTable;
use Modules\Minihouse\App\Models\ActivityLog;

// Chỉ ĐỌC — không có form/create/edit/delete, đúng bản chất "nhật ký" (ai sửa/xoá được log thì log
// không còn đáng tin). Quyền riêng view_any_activity_logs (không theo khuôn CRUD 4 quyền như các
// Resource khác) — xem MinihousePermissions::EXTRA_PERMISSIONS.
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;
    protected static ?string $navigationIcon  = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'Hệ thống';
    protected static ?string $navigationLabel = 'Nhật ký hoạt động';
    protected static ?int $navigationSort     = 95;

    public static function getModelLabel(): string { return 'Nhật ký hoạt động'; }
    public static function getPluralModelLabel(): string { return 'Nhật ký hoạt động'; }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_activity_logs') ?? false);
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function table(Table $table): Table
    {
        return ActivityLogTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }
}
