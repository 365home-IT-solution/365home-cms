<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\ReminderResource\Forms\ReminderForm;
use Modules\Minihouse\App\Filament\Resources\ReminderResource\Pages;
use Modules\Minihouse\App\Filament\Resources\ReminderResource\Tables\ReminderTable;
use Modules\Minihouse\App\Models\Reminder;

class ReminderResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Reminder::class;
    protected static ?string $navigationIcon  = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Nhắc việc';
    protected static ?int $navigationSort     = 7;

    public static function getModelLabel(): string
    {
        return 'Nhắc việc';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Nhắc việc';
    }

    public static function permissionGroup(): string
    {
        return 'reminders';
    }

    public static function form(Form $form): Form
    {
        return ReminderForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return ReminderTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListReminders::route('/'),
            'create' => Pages\CreateReminder::route('/create'),
            'edit'   => Pages\EditReminder::route('/{record}/edit'),
        ];
    }
}
