<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseItemResource\Forms;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;
use Modules\Minihouse\App\Filament\Support\WarehousePrinter;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\WarehouseCategory;
use Modules\Minihouse\App\Models\WarehouseUnit;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Mirror Modules\Minihouse\App\Filament\Resources\WarehouseItemResource\Forms\WarehouseItemForm
// (Home) — bỏ QR code preview (không mang theo tính năng in mã), giữ nguyên phần cốt lõi: chọn Toà
// nhà, danh mục/đơn vị (tạo nhanh ngay tại chỗ), và "quantity_in_use" không được vượt "quantity".
class WarehouseItemForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('building_id')
                ->label('Toà nhà')
                ->options(fn () => Building::withoutGlobalScope('activeBuilding')
                    ->whereIn('id', ActiveBuildingScope::permittedBuildingIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),

            TextInput::make('name')->label('Tên vật tư')->required()->columnSpanFull(),

            TextInput::make('sku')
                ->label('Mã SKU / mã vạch')
                ->helperText('Để trống nếu không cần quét mã — không kiểm tra trùng.')
                ->maxLength(100),

            Select::make('warehouse_category_id')
                ->label('Nhóm vật tư')
                ->relationship('category', 'name')
                ->searchable()
                ->createOptionForm([
                    TextInput::make('name')->label('Tên nhóm')->required()->maxLength(150),
                ]),

            Select::make('warehouse_unit_id')
                ->label('Đơn vị tính')
                ->relationship('unit', 'name')
                ->searchable()
                ->required()
                ->createOptionForm([
                    TextInput::make('name')->label('Tên đơn vị')->required()->maxLength(50),
                ]),

            TextInput::make('unit_price')
                ->label('Đơn giá')
                ->numeric()
                ->minValue(0)
                ->prefix('₫'),

            Grid::make(2)->schema([
                TextInput::make('quantity')
                    ->label('Số lượng tồn')
                    ->numeric()
                    ->default(0)
                    ->helperText('Sửa trực tiếp ở đây sẽ được ghi lại vào nhật ký điều chỉnh — nên nhập/xuất/kiểm kê qua đúng phiếu tương ứng thay vì sửa tay ở đây.'),
                TextInput::make('quantity_in_use')
                    ->label('Đang sử dụng')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(fn (Get $get) => (float) $get('quantity'))
                    ->helperText('Ghi chú thủ công, không tự trừ vào tồn kho.'),
                TextInput::make('min_quantity')->label('Tồn tối thiểu')->numeric()->default(0),
                Placeholder::make('quantity_reserve_display')
                    ->label('Còn khả dụng')
                    ->content(fn (Get $get) => max(0, (float) $get('quantity') - (float) $get('quantity_in_use'))),
            ]),

            // Xem trước mã QR encode đúng SKU (chỉ khi đang sửa và đã có SKU) — mirror form Home.
            Placeholder::make('qr_preview')
                ->label('Mã QR')
                ->content(fn ($record) => $record && filled($record->sku)
                    ? new HtmlString('<img src="data:image/png;base64,' . WarehousePrinter::qrPng((string) $record->sku) . '" alt="QR ' . e($record->sku) . '" style="width:100%;max-width:240px;aspect-ratio:1/1;background:#fff;">')
                    : 'Nhập SKU và lưu để có mã QR.')
                ->visibleOn('edit'),

            Toggle::make('status')->label('Đang sử dụng')->default(true),

            Textarea::make('description')->label('Mô tả')->columnSpanFull(),
        ]);
    }
}
