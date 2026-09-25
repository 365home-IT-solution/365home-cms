<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource\Forms\PortalBroadcastForm;
use Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource\Pages;
use Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource\Tables\PortalBroadcastTable;
use Modules\Minihouse\App\Models\PortalBroadcast;

// Soạn + gửi Thông báo đẩy hàng loạt cho khách thuê ngay trong panel — mirror
// App\Filament\Resources\NotificationFcmResource (Home), gửi thật qua cùng logic API
// (Api\Admin\Minihouse\PushNotificationController) — xem PortalBroadcastResource\Pages\
// CreatePortalBroadcast::handleRecordCreation() để biết vì sao KHÔNG tái dùng thẳng controller đó
// (Filament page không có Request thật, tự lấy building scope qua auth()->user() trực tiếp).
class PortalBroadcastResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = PortalBroadcast::class;

    protected static ?string $navigationIcon   = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationLabel  = 'Thông báo đẩy';
    protected static ?int    $navigationSort   = 61;

    public static function getModelLabel(): string
    {
        return 'Thông báo đẩy';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Thông báo đẩy';
    }

    // Dùng chung nhóm quyền 'tenants' — cùng nguyên tắc SurchargeResource dùng chung 'buildings'
    // (xem MinihousePermissions::RESOURCE_GROUPS) thay vì mở thêm 1 nhóm quyền mới chỉ cho tính năng
    // này. Khớp với 2 quyền view_any_tenants/update_tenants mà Api\Admin\Minihouse\
    // PushNotificationController đã dùng.
    public static function permissionGroup(): string
    {
        return 'tenants';
    }

    public static function form(Form $form): Form
    {
        return PortalBroadcastForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PortalBroadcastTable::table($table);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('title')->label('Tiêu đề')->weight('bold'),
            TextEntry::make('body')->label('Nội dung'),
            TextEntry::make('link')->label('Đường dẫn')->placeholder('—'),
            TextEntry::make('sent_for')->label('Gửi đến')->formatStateUsing(fn (string $s) => $s === PortalBroadcast::SENT_FOR_ALL ? 'Tất cả khách thuê' : 'Chọn từng khách'),
            TextEntry::make('recipient_count')->label('Số người nhận')->suffix(' khách'),
            TextEntry::make('scheduled_at')->label('Lịch gửi')->dateTime('d/m/Y H:i')->placeholder('Gửi ngay'),
            TextEntry::make('sent_at')->label('Đã gửi lúc')->dateTime('d/m/Y H:i')->placeholder('Chưa gửi'),
            TextEntry::make('creator.fullname')->label('Người gửi')->placeholder('—'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPortalBroadcasts::route('/'),
            'create' => Pages\CreatePortalBroadcast::route('/create'),
            'view'   => Pages\ViewPortalBroadcast::route('/{record}'),
            'edit'   => Pages\EditPortalBroadcast::route('/{record}/edit'),
        ];
    }
}
