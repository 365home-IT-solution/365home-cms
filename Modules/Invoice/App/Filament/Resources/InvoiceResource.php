<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Invoice\App\Filament\Resources\InvoiceResource\Forms\InvoiceForm;
use Modules\Invoice\App\Filament\Resources\InvoiceResource\Pages;
use Modules\Invoice\App\Filament\Resources\InvoiceResource\Tables\InvoiceTable;
use Modules\Invoice\App\Models\Invoice;

// Danh sách hoá đơn (nháp) được tạo từ nút "Xuất hoá đơn (nháp)" ở OrderResource — Resource này
// KHÔNG cho tạo mới thủ công (canCreate=false), vì mọi Invoice bắt buộc phải gắn với 1 Order thật
// và snapshot dữ liệu từ đơn hàng đó; tạo tay ở đây dễ tạo ra bản ghi hoá đơn không khớp đơn hàng
// nào — đúng tinh thần "không làm sườn có thể bị lạm dụng thành hoá đơn khống".
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-document-text';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationLabel(): string
    {
        return 'Hoá đơn điện tử';
    }

    public static function getModelLabel(): string
    {
        return 'Hoá đơn';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Hoá đơn điện tử';
    }

    public static function canCreate(): bool
    {
        return false;
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
            'index' => Pages\ListInvoice::route('/'),
            'edit'  => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
