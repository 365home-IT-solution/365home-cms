<?php

declare(strict_types=1);

namespace Modules\AuditLog\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\AuditLog\App\Filament\Resources\AuditLogResource\Pages;
use Modules\AuditLog\App\Filament\Resources\AuditLogResource\Tables\AuditLogTable;
use Modules\AuditLog\Entities\AuditLog;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-clipboard-document-list';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Phân quyền';
    }

    public static function getNavigationLabel(): string
    {
        return 'Lịch sử thao tác';
    }

    public static function getModelLabel(): string
    {
        return 'Lịch sử thao tác';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Lịch sử thao tác';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $_): bool
    {
        return false;
    }

    public static function canDelete(mixed $_): bool
    {
        return false;
    }

    private static function hasViewPermission(): bool
    {
        $user = auth()->user();

        return $user?->hasRole(config('filament-shield.super_admin.name'))
            || $user?->can('view_audit_logs')
            || $user?->can('view_any_audit::log');
    }

    /** Hiển thị trong navigation */
    public static function canViewAny(): bool
    {
        return static::hasViewPermission();
    }

    /** Kiểm tra khi truy cập URL trực tiếp */
    public static function canAccess(): bool
    {
        return static::hasViewPermission();
    }

    /**
     * Lọc dữ liệu theo người xem:
     *  - super_admin: thấy tất cả log
     *  - nhân viên có permission: chỉ thấy log của những user mà họ đã tạo ra
     *    (không thấy log của chính mình, không thấy log super_admin)
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user?->hasRole(config('filament-shield.super_admin.name'))) {
            return $query;
        }

        $subordinateIds = \App\Models\User::where('created_by', $user->id)->pluck('id');

        return $query->whereIn('user_id', $subordinateIds);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return AuditLogTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLog::route('/'),
        ];
    }
}
