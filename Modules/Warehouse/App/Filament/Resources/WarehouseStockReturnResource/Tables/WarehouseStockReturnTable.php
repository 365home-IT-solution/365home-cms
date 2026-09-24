<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Warehouse\App\Filament\Support\WarehousePrinter;
use Modules\Warehouse\App\Models\WarehouseStockReturn;

class WarehouseStockReturnTable
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

                TextColumn::make('creator.name')
                    ->label('Người nhận hoàn')
                    ->getStateUsing(fn (WarehouseStockReturn $record) => CurrentUserDisplay::forUser($record->creator))
                    ->searchable(query: fn ($query, string $search) => $query->whereHas(
                        'creator',
                        fn ($q) => $q->where('fullname', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                    )),

                TextColumn::make('room.name')
                    ->label('Phòng')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('returned_by')
                    ->label('Bộ phận / ghi chú')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('returned_at')
                    ->label('Ngày hoàn')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Số dòng')
                    ->counts('items'),

                PartnerTableHelpers::column()->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('branch.name')
                    ->label('Chi nhánh')
                    ->badge()
                    ->color('gray')
                    ->placeholder('— Chưa gán chi nhánh —')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('items'))
            ->defaultSort('returned_at', 'desc')
            ->actions([
                Action::make('print')
                    ->label('In phiếu')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(fn (WarehouseStockReturn $record) => WarehousePrinter::stockReturn($record)),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
