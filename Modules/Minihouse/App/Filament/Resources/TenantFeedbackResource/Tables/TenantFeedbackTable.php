<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantFeedbackResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Room;

class TenantFeedbackTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — tên khách + phòng/
                // số sao bên trái, ngày gửi + chấm màu trạng thái xử lý bên phải, đủ để lướt nhanh
                // không cần cuộn ngang. DÙNG ViewColumn (Filament\Tables\Columns\ViewColumn) — KHÔNG
                // PHẢI Tables\Columns\Layout\View — vì Layout\* là loại component KHÁC hẳn
                // (Table::hasColumnsLayout() bật true), đổi HẲN cách bảng render ẢNH HƯỞNG CẢ DESKTOP
                // dù chỉ định nghĩa 1 cột layout (đã thử và gặp lỗi này ở bảng khác, phải revert).
                // ViewColumn vẫn là Column bình thường nên bảng luôn ở đúng chế độ <table> cổ điển,
                // hiddenFrom/visibleFrom hoạt động đúng như các cột khác bên dưới.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.tenant-feedback-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                TextColumn::make('room.code')->label('Phòng')->placeholder('Chung')->searchable()->visibleFrom('md'),
                TextColumn::make('rating')->label('Đánh giá')->formatStateUsing(fn (int $state) => str_repeat('★', $state) . str_repeat('☆', 5 - $state))->sortable()->visibleFrom('md'),
                TextColumn::make('tenant_name')->label('Khách')->placeholder('Ẩn danh')->searchable()->visibleFrom('md'),
                TextColumn::make('content')->label('Góp ý')->limit(60)->wrap()->visibleFrom('md'),
                IconColumn::make('is_reviewed')->label('Đã xử lý')->boolean()->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày gửi')->dateTime('d/m/Y H:i')->sortable()->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('rating')
                    ->label('Đánh giá')
                    ->options([1 => '★', 2 => '★★', 3 => '★★★', 4 => '★★★★', 5 => '★★★★★']),
                SelectFilter::make('room_id')
                    ->label('Phòng')
                    ->options(fn () => Room::query()->pluck('code', 'id')),
                TernaryFilter::make('is_reviewed')
                    ->label('Trạng thái xử lý')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã xử lý')
                    ->falseLabel('Chưa xử lý'),
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
            ->paginated([10, 25, 50, 100]);
    }
}
