<?php

namespace Modules\Minihouse\App\Filament\Resources\AmenityResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;

class AmenityTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — cùng cơ chế đã áp
                // dụng cho TenantTable. DÙNG ViewColumn (Filament\Tables\Columns\ViewColumn) — KHÔNG
                // PHẢI Tables\Columns\Layout\View — xem ghi chú đầy đủ ở TenantTable::table().
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.amenity-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    // 190px = còn lại sau ô chọn nhiều (44px) + 2 nút Sửa/Xoá dạng icon (~124px).
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                ImageColumn::make('image')->label('Biểu tượng')->circular()->visibleFrom('md'),
                TextColumn::make('name')->label('Tên tiện ích')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('rooms_count')->label('Số phòng dùng')->counts('rooms')->sortable()->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->visibleFrom('md'),
            ])
            ->actions([
                // Ẩn chữ nhãn, chỉ giữ icon, RIÊNG dưới 768px — xem _mobile-action-styles.blade.php.
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                DeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            ->defaultSort('name')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
