<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\ContractResource\Forms\ContractForm;
use Modules\Minihouse\App\Filament\Resources\ContractResource\Pages;
use Modules\Minihouse\App\Filament\Resources\ContractResource\Tables\ContractTable;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Models\Contract;

class ContractResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Contract::class;
    protected static ?string $navigationIcon  = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Hợp đồng';
    protected static ?int $navigationSort     = 5;

    public static function getModelLabel(): string
    {
        return 'Hợp đồng';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Hợp đồng';
    }

    public static function permissionGroup(): string
    {
        return 'contracts';
    }

    public static function form(Form $form): Form
    {
        return ContractForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return ContractTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListContracts::route('/'),
            'create' => Pages\CreateContract::route('/create'),
            'edit'   => Pages\EditContract::route('/{record}/edit'),
        ];
    }
}
