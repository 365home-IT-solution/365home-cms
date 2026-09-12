<?php

namespace Modules\Minihouse\App\Filament\Resources\AnnouncementResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;

class AnnouncementTable
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
                    ->view('minihouse::filament.tables.announcement-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                TextColumn::make('title')->label('Tiêu đề')->searchable()->limit(60)->visibleFrom('md'),
                TextColumn::make('building.name')->label('Gửi cho')->placeholder('Tất cả toà nhà')->sortable()->visibleFrom('md'),
                TextColumn::make('createdBy.fullname')->label('Người đăng')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày đăng')->dateTime('d/m/Y H:i')->sortable()->visibleFrom('md'),
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
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
