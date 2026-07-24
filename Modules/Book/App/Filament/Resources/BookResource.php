<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources;

use Modules\Book\App\Filament\Resources\BookResource\Forms\BookForm;
use Modules\Book\App\Filament\Resources\BookResource\Tables\BookTable;
use Modules\Book\App\Filament\Resources\BookResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Product\App\Models\RoomTimeSlot;

class BookResource extends Resource
{
    protected static ?string $model = RoomTimeSlot::class;
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $navigationGroup = 'Quản lý';

    public static function getNavigationLabel(): string
    {
        return 'Hệ thống giá';
    }

    public static function getModelLabel(): string
    {
        return 'Book';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Book';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_book') ?? false;
    }

    public static function form(Form $form): Form
    {
        return BookForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return BookTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\SettingBook::route('/'),
        ];
    }
}
