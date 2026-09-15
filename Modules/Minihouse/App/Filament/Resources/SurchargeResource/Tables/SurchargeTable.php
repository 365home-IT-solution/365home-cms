<?php

namespace Modules\Minihouse\App\Filament\Resources\SurchargeResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Support\Money;

class SurchargeTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — tên phụ thu + toà
                // nhà bên trái, số tiền/ngày tạo + chấm màu trạng thái bên phải, đủ để lướt nhanh
                // không cần cuộn ngang. DÙNG ViewColumn (Filament\Tables\Columns\ViewColumn) — KHÔNG
                // PHẢI Tables\Columns\Layout\View — vì Layout\* là loại component KHÁC hẳn
                // (Table::hasColumnsLayout() bật true), đổi HẲN cách bảng render ẢNH HƯỞNG CẢ DESKTOP
                // dù chỉ định nghĩa 1 cột layout (đã thử và gặp lỗi này ở bảng khác, phải revert).
                // ViewColumn vẫn là Column bình thường nên bảng luôn ở đúng chế độ <table> cổ điển,
                // hiddenFrom/visibleFrom hoạt động đúng như các cột khác bên dưới.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.surcharge-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                TextColumn::make('building.name')->label('Toà nhà')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('name')->label('Tên phụ thu')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('amount')->label('Số tiền mặc định')->formatStateUsing(fn ($state) => Money::format($state))->sortable()->visibleFrom('md'),
                IconColumn::make('is_active')->label('Đang áp dụng')->boolean()->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('building_id')
                    ->label('Toà nhà')
                    ->options(fn () => Building::pluck('name', 'id')),
                TrashedFilter::make(),
            ])
            // Ẩn chữ nhãn nút Sửa/Xoá, chỉ giữ icon, RIÊNG dưới 768px — xem _mobile-action-styles.blade.php.
            ->actions([
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                DeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
                RestoreAction::make()->extraAttributes(['class' => 'mh-row-action']),
                ForceDeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            ->defaultSort('building_id')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
