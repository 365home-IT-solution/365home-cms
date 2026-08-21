<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Category\Entities\Category;
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
                PartnerTableHelpers::filter(),

                // Xem giải thích ở WarehouseItemTable — chỉ hiện khi tài khoản quản lý > 1 chi
                // nhánh (super_admin luôn hiện; quản lý đúng 1 thì không có gì để lọc thêm).
                SelectFilter::make('branch_id')
                    ->label('Chi nhánh')
                    ->options(function () {
                        $user = auth()->user();

                        if ($user?->isSuperAdmin()) {
                            return Category::query()
                                ->where('category_type', 'product')
                                ->whereNull('parent_id')
                                ->orderBy('name')
                                ->pluck('name', 'id');
                        }

                        return Category::query()
                            ->whereIn('id', $user?->rootProductCategoryIds() ?? [])
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->preload()
                    ->visible(fn () => (auth()->user()?->isSuperAdmin() ?? false) || count(auth()->user()?->rootProductCategoryIds() ?? []) > 1),
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
