<?php

declare(strict_types=1);

namespace Modules\Zns\App\Filament\Resources;

use Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Forms\ZnsNotificationForm;
use Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Tables\Actions\ZnsNotificationAction;
use Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Tables\ZnsNotificationTable;
use Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Pages;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Zns\App\Models\ZnsNotification;

class ZnsNotificationResource extends Resource
{
    protected static ?string $model = ZnsNotification::class;

    protected static ?string $navigationIcon = 'heroicon-c-bell-snooze';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Thông báo ZNS';
    }

    public static function getModelLabel(): string
    {
        return 'Thông báo ZNS';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Thông báo ZNS';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_zns::notification') ?? false;
    }

    // public static function getNavigationBadge(): ?string
    // {
    //     return static::getModel()::count();
    // }

    public static function form(Form $form): Form
    {
        return ZnsNotificationForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return ZnsNotificationTable::table($table)
        ->actions(
            ZnsNotificationAction::action()
        );
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return ZnsNotificationTable::infolist($infolist);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZnsNotification::route('/'),
            'create' => Pages\CreateZnsNotification::route('/create'),
            'edit' => Pages\EditZnsNotification::route('/{record}/edit'),
            'view' => Pages\ViewZnsNotification::route('/{record}'),
        ];
    }
}