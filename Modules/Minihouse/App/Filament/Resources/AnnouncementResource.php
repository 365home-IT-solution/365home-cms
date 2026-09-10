<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\AnnouncementResource\Forms\AnnouncementForm;
use Modules\Minihouse\App\Filament\Resources\AnnouncementResource\Pages;
use Modules\Minihouse\App\Filament\Resources\AnnouncementResource\Tables\AnnouncementTable;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Models\Announcement;

// Thông báo chung chủ nhà tự đăng cho khách thuê xem trong Portal (xem Announcement::class,
// AnnouncementObserver — tạo xong tự phát ra minihouse_portal_notifications).
class AnnouncementResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Announcement::class;
    protected static ?string $navigationIcon  = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Thông báo';
    protected static ?int $navigationSort     = 60;

    public static function getModelLabel(): string
    {
        return 'Thông báo';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Thông báo';
    }

    public static function permissionGroup(): string
    {
        return 'announcements';
    }

    public static function form(Form $form): Form
    {
        return AnnouncementForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return AnnouncementTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit'   => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
