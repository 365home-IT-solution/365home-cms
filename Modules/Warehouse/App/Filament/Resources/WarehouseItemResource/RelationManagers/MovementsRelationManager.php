<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Warehouse\App\Models\WarehouseStockMovement;

// Sổ nhật ký biến động tồn kho (kardex) — gộp CẢ 3 nguồn nhập/xuất/kiểm kê theo đúng thứ tự thời
// gian thật (xem WarehouseStockMovement + migration VIEW warehouse_stock_movements). CHỈ ĐỌC: không
// có tạo/sửa/xoá ở đây, mọi thay đổi tồn kho vẫn phải đi qua đúng phiếu nhập/xuất/kiểm kê.
class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Lịch sử biến động';

    protected static ?string $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Ngày chứng từ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => WarehouseStockMovement::TYPE_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'in'         => 'success',
                        'out'        => 'danger',
                        'check'      => 'warning',
                        'adjustment' => 'gray',
                        default      => 'gray',
                    }),

                TextColumn::make('document_code')
                    ->label('Số phiếu')
                    ->searchable()
                    ->fontFamily('mono'),

                TextColumn::make('quantity_change')
                    ->label('Biến động')
                    ->numeric(maxDecimalPlaces: 2)
                    ->formatStateUsing(fn (float $state): string => ($state > 0 ? '+' : '') . rtrim(rtrim(number_format($state, 2, ',', '.'), '0'), ','))
                    ->color(fn (float $state): string => $state >= 0 ? 'success' : 'danger')
                    ->weight('bold'),

                TextColumn::make('balance_after')
                    ->label('Tồn sau biến động')
                    ->numeric(maxDecimalPlaces: 2),

                TextColumn::make('note')
                    ->label('Ghi chú')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('creator')
                    ->label('Người thực hiện')
                    ->getStateUsing(fn (WarehouseStockMovement $record) => CurrentUserDisplay::forUser($record->creator)),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại')
                    ->options(WarehouseStockMovement::TYPE_LABELS),
            ])
            ->defaultSort(fn ($query) => $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('entry_created_at')
                ->orderByDesc('id'))
            ->paginated([10, 25, 50])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
