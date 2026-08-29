<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Warehouse\App\Filament\Support\WarehousePrinter;
use Modules\Warehouse\App\Models\WarehouseStockOut;

class WarehouseStockOutTable
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

                TextColumn::make('reasons')
                    ->label('Lý do')
                    // "Lý do" giờ ở cấp DÒNG (warehouse_stock_out_items.reason, xem
                    // WarehouseStockOut::reasonsSummary()) — 1 phiếu có thể gồm nhiều lý do khác
                    // nhau (vừa hao hụt vừa housekeeping bình thường trong cùng 1 lượt dọn phòng),
                    // nên cột này tóm tắt TẤT CẢ lý do đang có trong phiếu, không phải 1 giá trị.
                    ->getStateUsing(fn (WarehouseStockOut $record) => $record->reasonsSummary() ?: '—')
                    ->wrap(),

                TextColumn::make('creator.name')
                    ->label('Người xuất kho')
                    // creator.name có thể rỗng nếu tài khoản chưa đặt "Họ tên" trong hồ sơ (đọc
                    // thẳng cột name/fullname sẽ ra chuỗi rỗng, KHÔNG null, nên ->placeholder()
                    // không kích hoạt) — dùng cùng fallback với Placeholder lúc tạo phiếu (đổi qua
                    // phần trước @ của email khi thiếu tên) để không hiện "—" oan cho các phiếu đã
                    // có người tạo thật.
                    ->getStateUsing(fn (WarehouseStockOut $record) => CurrentUserDisplay::forUser($record->creator))
                    ->searchable(query: fn ($query, string $search) => $query->whereHas(
                        'creator',
                        fn ($q) => $q->where('fullname', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                    )),

                TextColumn::make('room.name')
                    ->label('Phòng')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('issued_to')
                    ->label('Bộ phận / ghi chú')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('issued_at')
                    ->label('Ngày xuất')
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
            ->filters([
                SelectFilter::make('reason')
                    ->label('Lý do')
                    ->options(WarehouseStockOut::REASONS)
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('items', fn ($q) => $q->where('reason', $data['value']))
                        : $query),

                // Không còn filter "Đối tác"/"Chi nhánh" thủ công ở đây nữa — model WarehouseStockOut
                // đã có global scope 'branch' (BelongsToBranch, xem app/Models/Concerns/BelongsToBranch.php)
                // tự lọc theo User::effectiveBranchIds() (đúng chi nhánh đang active ở header
                // "Chuyển đổi chi nhánh"), nên danh sách hiện ra ĐÃ đúng phạm vi rồi — filter thêm ở
                // đây chỉ là lọc lại 1 lần nữa trên dữ liệu vốn đã được lọc sẵn, thừa.
            ])
            ->defaultSort('issued_at', 'desc')
            ->actions([
                Action::make('print')
                    ->label('In phiếu')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(fn (WarehouseStockOut $record) => WarehousePrinter::stockOut($record)),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
