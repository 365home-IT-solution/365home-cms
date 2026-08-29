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
     *  - super_admin: thấy tất cả log (mọi đối tác).
     *  - Tài khoản có quyền xem (chủ đối tác/nhân viên được cấp quyền): thấy TOÀN BỘ log TRONG
     *    PHẠM VI ĐỐI TÁC CỦA HỌ — kể cả log do chính họ tạo ra và log của bất kỳ nhân viên nào
     *    khác cùng đối tác (không chỉ những ai họ tự tay tạo tài khoản). AuditLog đã `use
     *    BelongsToPartner` nên global scope + creating() đã tự lọc/gán đúng partner_id — parent::
     *    getEloquentQuery() ở đây ĐÃ áp dụng đúng phạm vi đối tác rồi, không cần lọc thêm gì nữa.
     *    (Trước đây có thu hẹp thêm theo whereIn('user_id', $subordinateIds) — chỉ hiện log của
     *    những user do chính người xem tạo ra — khiến chủ đối tác không thấy được cả log của
     *    chính mình lẫn log của nhân viên do người khác tạo, sai với yêu cầu "xem log trong phạm
     *    vi đối tác của họ".)
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
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
