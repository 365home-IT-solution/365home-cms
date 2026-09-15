<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Exports\TenantExporter;
use Modules\Minihouse\App\Models\Room;

class TenantTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — ảnh đại diện viết
                // tắt tên + họ tên/SĐT bên trái, phòng/CCCD (ẩn bớt số)/chấm màu trạng thái KBTT bên
                // phải, đủ để lướt nhanh không cần cuộn ngang. DÙNG ViewColumn (Filament\Tables\
                // Columns\ViewColumn) — KHÔNG PHẢI Tables\Columns\Layout\View — vì Layout\* là loại
                // component KHÁC hẳn (Table::hasColumnsLayout() bật true), đổi HẲN cách bảng render
                // ẢNH HƯỞNG CẢ DESKTOP dù chỉ định nghĩa 1 cột layout (đã thử và gặp lỗi này, phải
                // revert). ViewColumn vẫn là Column bình thường nên bảng luôn ở đúng chế độ <table>
                // cổ điển, hiddenFrom/visibleFrom hoạt động đúng như các cột khác bên dưới.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.tenant-mobile-row')
                    ->hiddenFrom('md')
                    // max-w-0/w-full qua class Tailwind KHÔNG có tác dụng ở đây — class đó chưa
                    // từng được dùng nơi khác trong panel nên không có trong CSS đã build sẵn, cần
                    // rebuild asset mới nhận (không khả thi ngay). Dùng style nội tuyến (luôn có
                    // hiệu lực, không phụ thuộc Tailwind build) để ép <table table-auto> thật sự co
                    // cột này lại — nếu không, dù mọi div con đã có "truncate", cột vẫn bị đo theo
                    // độ dài chữ CHƯA cắt (đặc tính table-auto), đẩy cả bảng rộng hơn màn hình. Trị
                    // số 190px = 358px (khung nội dung mobile thực đo) − 44px (ô chọn nhiều) − 124px
                    // (2 nút Sửa/Xoá dạng icon sau khi ẩn chữ, xem _mobile-action-styles.blade.php).
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                ImageColumn::make('id_card_front')->label('CCCD')->circular()->visibleFrom('md'),
                TextColumn::make('fullname')->label('Họ tên')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('phone')->label('Điện thoại')->searchable()->visibleFrom('md'),
                TextColumn::make('id_card_number')->label('CCCD/CMND')->searchable()->visibleFrom('md'),
                TextColumn::make('room.code')->label('Phòng đang ở')->searchable()->sortable()->visibleFrom('md'),
                IconColumn::make('residence_declared')->label('Đã khai báo tạm trú')->boolean()->visibleFrom('md'),
                TextColumn::make('date_of_birth')->label('Ngày sinh')->date('d/m/Y')->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
                TextColumn::make('emergency_contact_phone')->label('SĐT khẩn cấp')->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('room_id')
                    ->label('Phòng đang ở')
                    ->options(fn () => Room::query()->pluck('code', 'id')),
                TernaryFilter::make('room_id')
                    ->label('Đang thuê phòng?')
                    ->nullable()
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('room_id'),
                        false: fn ($query) => $query->whereNull('room_id'),
                    ),
                TernaryFilter::make('residence_declared')
                    ->label('Đã khai báo tạm trú?')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã khai báo')
                    ->falseLabel('Chưa khai báo'),
                TrashedFilter::make(),
            ])
            ->actions([
                // ->extraAttributes(class: mh-row-action) — xem _mobile-action-styles.blade.php: ẩn
                // chữ nhãn ("Chỉnh sửa"/"Xóa"), chỉ giữ icon, RIÊNG dưới 768px — nút hành động không
                // nằm trong ->columns() nên không tự thu gọn theo visibleFrom('md') như các cột khác.
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                DeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
                RestoreAction::make()->extraAttributes(['class' => 'mh-row-action']),
                ForceDeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
                ExportBulkAction::make()->label('Xuất Excel (đã chọn)')->exporter(TenantExporter::class),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
