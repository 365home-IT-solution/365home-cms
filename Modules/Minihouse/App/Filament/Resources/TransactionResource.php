<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\TransactionResource\Forms\TransactionForm;
use Modules\Minihouse\App\Filament\Resources\TransactionResource\Pages;
use Modules\Minihouse\App\Filament\Resources\TransactionResource\Tables\TransactionTable;
use Modules\Minihouse\App\Models\Transaction;

class TransactionResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Transaction::class;
    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Sổ thu chi';
    protected static ?int $navigationSort     = 7;

    public static function getModelLabel(): string
    {
        return 'Thu chi';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Sổ thu chi';
    }

    public static function permissionGroup(): string
    {
        return 'transactions';
    }

    public static function form(Form $form): Form
    {
        return TransactionForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return TransactionTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit'   => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }
}
