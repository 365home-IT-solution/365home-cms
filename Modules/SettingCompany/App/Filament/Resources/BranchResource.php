<?php

namespace Modules\SettingCompany\App\Filament\Resources;

use Modules\SettingCompany\App\Filament\Resources\BranchResource\Forms\BranchForm;
use Modules\SettingCompany\App\Filament\Resources\BranchResource\Tables\BranchTable;
use Modules\SettingCompany\App\Filament\Resources\BranchResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\SettingCompany\Entities\Branch as EntitiesBranch;
use Illuminate\Database\Eloquent\Model;

class BranchResource extends Resource
{
    protected static ?string $model = EntitiesBranch::class;
    public static function getNavigationIcon(): string
    {
        return __('settingcompany::branch.resource.navigation_icon');
    }
    public static function getNavigationGroup(): ?string
    {
        return __('settingcompany::branch.resource.navigation_group');
    }
    public static function getNavigationLabel(): string
    {
        return __('settingcompany::branch.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('settingcompany::branch.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('settingcompany::branch.resource.plural_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return BranchForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return BranchTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranch::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }

    // Chi nhánh (văn phòng công ty, hiển thị ở footer...) là dữ liệu TOÀN NỀN TẢNG, không gắn
    // với riêng đối tác nào (Branch/Business không có partner_id) — hard-code CHỈ super_admin,
    // giống PartnerResource, không dựa vào permission Shield (đối tác trước đây vẫn vào được nhờ
    // permission Shield lỡ cấp, bấm Lưu thì lỗi vì nghiệp vụ này chưa từng tính tới tài khoản đối
    // tác — ẩn hẳn khỏi họ thay vì cố sửa form cho đúng ngữ cảnh không áp dụng).
    private static function isSuperAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return self::isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return self::isSuperAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return self::isSuperAdmin();
    }

    public static function canView(Model $record): bool
    {
        return self::isSuperAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return self::isSuperAdmin();
    }
}
