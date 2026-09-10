<?php

namespace Modules\Minihouse\App\Filament\Resources;

use App\Models\User;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Minihouse\App\Filament\Resources\UserResource\Forms\UserForm;
use Modules\Minihouse\App\Filament\Resources\UserResource\Pages;
use Modules\Minihouse\App\Filament\Resources\UserResource\Tables\UserTable;
use Modules\Minihouse\App\Support\MinihousePermissions;

// Quản lý TÀI KHOẢN ngay trong panel MiniHouse — dùng CHUNG bảng users với Home (không tách tài
// khoản riêng, theo đúng kiến trúc đã chọn khi xây MiniHouse — xem App\Models\User::canAccessPanel())
// nhưng TÁCH HẲN màn hình quản lý: getEloquentQuery() chỉ hiện tài khoản có liên quan tới MiniHouse
// (quyền access_minihouse trực tiếp, qua vai trò, hoặc super_admin) — không thấy/động vào được danh
// sách nhân viên Home. Ngược lại, UserResource của Home cũng không đọc/sửa gì ở đây.
//
// CHỈ super_admin mới truy cập được — tạo tài khoản + gán vai trò là thao tác nhạy cảm, không dùng
// AuthorizesByPermission (permission theo nhóm CRUD nghiệp vụ thường) cho màn hình này.
class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Phân quyền';
    protected static ?string $navigationLabel = 'Tài khoản';
    protected static ?int $navigationSort     = 89;

    public static function getModelLabel(): string { return 'Tài khoản'; }
    public static function getPluralModelLabel(): string { return 'Tài khoản'; }

    public static function getEloquentQuery(): Builder
    {
        return MinihousePermissions::scopeToMinihouseUsers(parent::getEloquentQuery());
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        // Không tự xoá được chính mình.
        return static::canViewAny() && $record->getKey() !== auth()->id();
    }

    public static function form(Form $form): Form { return UserForm::form($form); }
    public static function table(Table $table): Table { return UserTable::table($table); }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
