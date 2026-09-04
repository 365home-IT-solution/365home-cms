<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\TenantResource\Forms\TenantForm;
use Modules\Minihouse\App\Filament\Resources\TenantResource\Pages;
use Modules\Minihouse\App\Filament\Resources\TenantResource\Tables\TenantTable;
use Modules\Minihouse\App\Models\Tenant;

class TenantResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Tenant::class;
    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Khách thuê';
    protected static ?int $navigationSort     = 3;

    public static function getModelLabel(): string
    {
        return 'Khách thuê';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Khách thuê';
    }

    public static function permissionGroup(): string
    {
        return 'tenants';
    }

    public static function form(Form $form): Form
    {
        return TenantForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return TenantTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
