<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerResource\Forms\PartnerForm;
use App\Filament\Resources\PartnerResource\Pages;
use App\Filament\Resources\PartnerResource\Tables\PartnerTable;
use App\Models\Partner;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

// Quản lý đối tác đầy đủ — CHỈ super_admin được tạo/xem/sửa (đối tác/nhân viên không được tạo
// đối tác khác, xem UserForm::createAccountTypeSelector()). Không dựa vào permission Shield mà
// hard-code như RoomImageResource, vì đây là nghiệp vụ nhạy cảm (thông tin pháp lý/tài chính).
class PartnerResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Đối tác';

    protected static ?string $modelLabel = 'Đối tác';

    protected static ?string $pluralModelLabel = 'Đối tác';

    protected static ?string $slug = 'partners';

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return PartnerForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PartnerTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'         => Pages\ListPartners::route('/'),
            'create'        => Pages\CreatePartner::route('/create'),
            'edit'          => Pages\EditPartner::route('/{record}/edit'),
            'branch-detail' => Pages\BranchDetail::route('/{record}/branches/{branch}'),
        ];
    }

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
