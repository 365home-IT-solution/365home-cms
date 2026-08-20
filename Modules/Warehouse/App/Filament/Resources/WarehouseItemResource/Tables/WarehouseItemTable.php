<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Warehouse\App\Models\WarehouseCategory;
use Modules\Warehouse\App\Models\WarehouseItem;

class WarehouseItemTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('name')
                    ->label('Tên vật tư')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Nhóm')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('unit.name')
                    ->label('ĐVT')
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Tồn kho')
                    ->numeric(maxDecimalPlaces: 2)
                    ->sortable()
                    ->badge()
                    ->color(fn (WarehouseItem $record) => $record->isLowStock() ? 'danger' : 'success'),

                TextColumn::make('min_quantity')
                    ->label('Ngưỡng tối thiểu')
                    ->numeric(maxDecimalPlaces: 2)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('unit_price')
                    ->label('Đơn giá')
                    ->money('VND')
                    ->toggleable(),

                ToggleColumn::make('status')
                    ->label('Đang dùng'),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Mặc định ẨN — cột này chủ yếu để super_admin đối chiếu khi cần, nhân viên đối
                // tác thường không cần thấy hàng ngày, để mặc định hiện sẽ chiếm chỗ vô ích.
                PartnerTableHelpers::column()->toggledHiddenByDefault(),
            ])
            ->filters([
                SelectFilter::make('warehouse_category_id')
                    ->label('Nhóm')
                    ->options(fn () => WarehouseCategory::orderBy('name')->pluck('name', 'id')),

                TernaryFilter::make('low_stock')
                    ->label('Sắp hết hàng')
                    ->queries(
                        true: fn ($query) => $query->whereColumn('quantity', '<=', 'min_quantity')->where('min_quantity', '>', 0),
                        false: fn ($query) => $query,
                        blank: fn ($query) => $query,
                    ),

                PartnerTableHelpers::filter(),
            ])
            ->defaultSort('name')
            ->actions([
                // Xem NHANH lịch sử biến động ngay tại danh sách — không cần mở hẳn trang "Sửa vật
                // tư" mới thấy được tab "Lịch sử biến động" (MovementsRelationManager). Cùng dữ
                // liệu/cột, hiện qua modal — xem item-history-modal.blade.php.
                Action::make('history')
                    ->label('Lịch sử')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (WarehouseItem $record) => 'Lịch sử biến động — ' . $record->name)
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (WarehouseItem $record) => view('warehouse::filament.tables.item-history-modal', [
                        'movements' => $record->movements()
                            ->orderByDesc('occurred_at')
                            ->orderByDesc('entry_created_at')
                            ->orderByDesc('id')
                            ->limit(50)
                            ->get(),
                    ])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
