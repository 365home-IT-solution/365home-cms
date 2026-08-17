<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Warehouse\App\Filament\Support\WarehousePrinter;
use Modules\Warehouse\App\Models\WarehouseStockCheck;

class WarehouseStockCheckTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã phiếu')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('checked_at')
                    ->label('Ngày kiểm kê')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Số dòng')
                    ->counts('items'),

                TextColumn::make('items_sum_difference')
                    ->label('Tổng chênh lệch')
                    ->sum('items', 'difference')
                    ->numeric(maxDecimalPlaces: 2)
                    ->color(fn ($state) => (float) $state === 0.0 ? 'gray' : ((float) $state > 0 ? 'success' : 'danger')),

                TextColumn::make('creator.name')
                    ->label('Người kiểm kê')
                    // creator.name có thể rỗng nếu tài khoản chưa đặt "Họ tên" trong hồ sơ (đọc
                    // thẳng cột name/fullname sẽ ra chuỗi rỗng, KHÔNG null, nên ->placeholder()
                    // không kích hoạt) — dùng cùng fallback với Placeholder lúc tạo phiếu.
                    ->getStateUsing(fn (WarehouseStockCheck $record) => CurrentUserDisplay::forUser($record->creator))
                    ->searchable(query: fn ($query, string $search) => $query->whereHas(
                        'creator',
                        fn ($q) => $q->where('fullname', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                    )),

                TextColumn::make('handover_status')
                    ->label('Bàn giao ca')
                    ->badge()
                    ->placeholder('Chưa bàn giao')
                    ->formatStateUsing(fn (?string $state) => $state ? WarehouseStockCheck::HANDOVER_LABELS[$state] ?? $state : 'Chưa bàn giao')
                    ->color(fn (?string $state) => match ($state) {
                        WarehouseStockCheck::HANDOVER_CONFIRMED   => 'success',
                        WarehouseStockCheck::HANDOVER_DISCREPANCY => 'danger',
                        default                                   => 'gray',
                    }),

                PartnerTableHelpers::column()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                PartnerTableHelpers::filter(),
            ])
            ->defaultSort('checked_at', 'desc')
            ->actions([
                Action::make('print')
                    ->label('In phiếu')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(fn (WarehouseStockCheck $record) => WarehousePrinter::stockCheck($record)),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
