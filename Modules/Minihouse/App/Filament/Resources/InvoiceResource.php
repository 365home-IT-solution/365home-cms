<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\InvoiceResource\Forms\InvoiceForm;
use Modules\Minihouse\App\Filament\Resources\InvoiceResource\Pages;
use Modules\Minihouse\App\Filament\Resources\InvoiceResource\Tables\InvoiceTable;
use Modules\Minihouse\App\Models\Invoice;

class InvoiceResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Invoice::class;
    protected static ?string $navigationIcon  = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Hoá đơn';
    protected static ?int $navigationSort     = 6;

    public static function getModelLabel(): string
    {
        return 'Hoá đơn';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Hoá đơn';
    }

    public static function permissionGroup(): string
    {
        return 'invoices';
    }

    public static function form(Form $form): Form
    {
        return InvoiceForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return InvoiceTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit'   => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
