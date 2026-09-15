<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomResource\Tables;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Support\Money;

class RoomTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — ảnh/mã phòng bên
                // trái, toà nhà bên trái dòng phụ, giá/diện tích + chấm màu trạng thái bên phải, đủ
                // để lướt nhanh không cần cuộn ngang. DÙNG ViewColumn (Filament\Tables\Columns\
                // ViewColumn) — KHÔNG PHẢI Tables\Columns\Layout\View — vì Layout\* là loại
                // component KHÁC hẳn (Table::hasColumnsLayout() bật true), đổi HẲN cách bảng render
                // ẢNH HƯỞNG CẢ DESKTOP dù chỉ định nghĩa 1 cột layout (đã thử và gặp lỗi này ở bảng
                // khác, phải revert). ViewColumn vẫn là Column bình thường nên bảng luôn ở đúng chế
                // độ <table> cổ điển, hiddenFrom/visibleFrom hoạt động đúng như các cột khác bên dưới.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.room-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    // 150px (hẹp hơn — bảng này có 3 nút hành động: Link phản hồi/Sửa/Xoá).
                    ->extraCellAttributes(['style' => 'max-width: 150px; width: 100%; overflow: hidden;']),

                ImageColumn::make('photos')
                    ->label('Ảnh')
                    ->getStateUsing(fn (Room $record) => $record->photos[0] ?? null)
                    ->circular()
                    ->visibleFrom('md'),
                TextColumn::make('code')->label('Mã / Tên phòng')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('floor')->label('Tầng')->sortable()->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
                TextColumn::make('position_row')->label('Hàng')->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
                TextColumn::make('position_col')->label('Cột')->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
                TextColumn::make('building.name')->label('Toà nhà')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('area')->label('Diện tích')->suffix(' m²')->sortable()->visibleFrom('md'),
                TextColumn::make('price')->label('Giá thuê')->formatStateUsing(fn ($state) => Money::format($state))->sortable()->visibleFrom('md'),
                TextColumn::make('status')->label('Tình trạng')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    Room::STATUS_EMPTY    => 'Trống',
                    Room::STATUS_RESERVED => 'Đã đặt cọc',
                    Room::STATUS_RENTED   => 'Đã thuê',
                    Room::STATUS_REPAIR   => 'Đã khoá',
                    default => $state,
                })->color(fn (string $state) => match ($state) {
                    Room::STATUS_EMPTY    => 'success',
                    Room::STATUS_RESERVED => 'info',
                    Room::STATUS_RENTED   => 'warning',
                    Room::STATUS_REPAIR   => 'danger',
                    default => 'gray',
                })->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('building_id')
                    ->label('Toà nhà')
                    ->options(fn () => Building::query()->pluck('name', 'id')),
                SelectFilter::make('status')
                    ->label('Tình trạng')
                    ->options([
                        Room::STATUS_EMPTY    => 'Trống',
                        Room::STATUS_RESERVED => 'Đã đặt cọc',
                        Room::STATUS_RENTED   => 'Đã thuê',
                        Room::STATUS_REPAIR   => 'Đã khoá',
                    ]),
                TrashedFilter::make(),
            ])
            ->actions([
                // Đưa link công khai (xem TenantFeedbackController) để dán QR trong phòng hoặc gửi
                // khách qua Zalo lúc bàn giao — không cần đăng nhập, khách chấm sao + góp ý trực tiếp.
                // ->extraAttributes(class: mh-row-action) trên cả 3 nút — ẩn chữ nhãn, chỉ giữ icon,
                // RIÊNG dưới 768px (xem _mobile-action-styles.blade.php).
                Action::make('feedbackLink')
                    ->label('Link phản hồi')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('gray')
                    ->extraAttributes(['class' => 'mh-row-action'])
                    ->action(function (Room $record) {
                        Notification::make()
                            ->title('Link phản hồi phòng ' . $record->code)
                            ->body(route('minihouse.feedback.create', ['room' => $record->id]))
                            ->persistent()
                            ->send();
                    }),
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                // Xem chú thích ở EditRoom::getHeaderActions() — chặn xoá phòng còn Hợp đồng (kể cả
                // đã kết thúc) tham chiếu tới, tránh Contract.room_id trỏ về 1 Room đã "biến mất".
                DeleteAction::make()
                    ->extraAttributes(['class' => 'mh-row-action'])
                    ->before(function (DeleteAction $action, Room $record) {
                        if ($record->contracts()->exists()) {
                            Notification::make()
                                ->title('Không thể xoá')
                                ->body('Phòng "' . $record->code . '" vẫn còn Hợp đồng (kể cả đã kết thúc) tham chiếu tới — không thể xoá để giữ nguyên lịch sử.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
                RestoreAction::make()->extraAttributes(['class' => 'mh-row-action']),
                ForceDeleteAction::make()
                    ->extraAttributes(['class' => 'mh-row-action'])
                    ->before(function (ForceDeleteAction $action, Room $record) {
                        if ($record->contracts()->exists()) {
                            Notification::make()
                                ->title('Không thể xoá vĩnh viễn')
                                ->body('Phòng "' . $record->code . '" vẫn còn Hợp đồng (kể cả đã kết thúc) tham chiếu tới — không thể xoá để giữ nguyên lịch sử.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->before(function (DeleteBulkAction $action, Collection $records) {
                        $blocked = $records->filter(fn (Room $room) => $room->contracts()->exists());

                        if ($blocked->isNotEmpty()) {
                            Notification::make()
                                ->title('Không thể xoá')
                                ->body('Các phòng sau vẫn còn Hợp đồng tham chiếu tới: ' . $blocked->pluck('code')->implode(', ') . '.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make()
                    ->before(function (ForceDeleteBulkAction $action, Collection $records) {
                        $blocked = $records->filter(fn (Room $room) => $room->contracts()->exists());

                        if ($blocked->isNotEmpty()) {
                            Notification::make()
                                ->title('Không thể xoá vĩnh viễn')
                                ->body('Các phòng sau vẫn còn Hợp đồng tham chiếu tới: ' . $blocked->pluck('code')->implode(', ') . '.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
