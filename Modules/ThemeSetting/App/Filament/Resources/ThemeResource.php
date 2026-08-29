<?php

namespace Modules\ThemeSetting\App\Filament\Resources;

use Filament\Actions\EditAction;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\Forms\ThemeForm;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\Tables\ThemeTable;
use Modules\ThemeSetting\App\Models\Theme;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Tables\ThemeSectionRelationManager;

class ThemeResource extends Resource
{
    protected static ?string $model = Theme::class;

    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationGroup = 'Cấu hình web';

    // Ẩn hẳn khỏi admin — xác nhận "Theme" (cả resource này lẫn ThemeStudioResource, dùng chung 1
    // bảng dữ liệu) KHÔNG được dùng để hiển thị web thật: trang chủ hard-code cứng sẵn 3 section,
    // màu sắc web đọc từ GeneralSettings riêng — Theme chỉ là tính năng dở dang, chưa từng nối vào
    // luồng render nào. Ẩn an toàn tuyệt đối, không đụng dữ liệu/logic đang chạy.
    public static function canViewAny(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return 'Theme';
    }

    public static function getModelLabel(): string
    {
        return 'Theme';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Theme';
    }

    public static function getNavigationBadge(): ?string
    {
        $activeTheme = static::getModel()::where('is_active', true)->first();

        return (string) $activeTheme ? $activeTheme->name : null;
    }

    public static function form(Form $form): Form
    {
        return ThemeForm::form($form);
    }

    public static function headerActions()
    {
        return [
            CreateAction::make()->hidden(true),
        ];
    }

    public static function table(Table $table): Table
    {
        return ThemeTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            ThemeSectionRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTheme::route('/'),
            'create' => Pages\CreateTheme::route('/create'),
            'edit' => Pages\EditTheme::route('/{record}/edit'),
        ];
    }
}
