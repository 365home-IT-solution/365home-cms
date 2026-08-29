<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Warehouse\App\Filament\Support\WarehousePrinter;
use Modules\Warehouse\App\Models\WarehouseStockIn;

class WarehouseStockInTable
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

                TextColumn::make('received_at')
                    ->label('Ngày nhập')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Số dòng')
                    ->counts('items'),

                TextColumn::make('total_amount')
                    ->label('Tổng tiền')
                    ->money('VND')
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label('Người nhập kho')
                    // creator.name có thể rỗng nếu tài khoản chưa đặt "Họ tên" trong hồ sơ (đọc
                    // thẳng cột name/fullname sẽ ra chuỗi rỗng, KHÔNG null, nên ->placeholder()
                    // không kích hoạt) — dùng cùng fallback với Placeholder lúc tạo phiếu.
                    ->getStateUsing(fn (WarehouseStockIn $record) => CurrentUserDisplay::forUser($record->creator))
                    ->searchable(query: fn ($query, string $search) => $query->whereHas(
                        'creator',
                        fn ($q) => $q->where('fullname', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                    )),

                PartnerTableHelpers::column()->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('branch.name')
                    ->label('Chi nhánh')
                    ->badge()
                    ->color('gray')
                    ->placeholder('— Chưa gán chi nhánh —')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
            ])
            ->filters([
                // Không còn filter "Đối tác"/"Chi nhánh" thủ công ở đây nữa — model WarehouseStockIn
                // đã có global scope 'branch' (BelongsToBranch, xem app/Models/Concerns/BelongsToBranch.php)
                // tự lọc theo User::effectiveBranchIds() (đúng chi nhánh đang active ở header
                // "Chuyển đổi chi nhánh"), nên danh sách hiện ra ĐÃ đúng phạm vi rồi — filter thêm ở
                // đây chỉ là lọc lại 1 lần nữa trên dữ liệu vốn đã được lọc sẵn, thừa.
            ])
            ->defaultSort('received_at', 'desc')
            ->actions([
                Action::make('print')
                    ->label('In phiếu')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(fn (WarehouseStockIn $record) => WarehousePrinter::stockIn($record)),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
