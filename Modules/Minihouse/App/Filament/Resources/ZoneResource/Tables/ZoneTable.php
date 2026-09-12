<?php

namespace Modules\Minihouse\App\Filament\Resources\ZoneResource\Tables;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Modules\Minihouse\App\Models\Zone;

class ZoneTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — tên khu vực/ghi
                // chú bên trái, số toà nhà bên phải, đủ để lướt nhanh không cần cuộn ngang. DÙNG
                // ViewColumn (Filament\Tables\Columns\ViewColumn) — KHÔNG PHẢI Tables\Columns\
                // Layout\View — vì Layout\* là loại component KHÁC hẳn (Table::hasColumnsLayout()
                // bật true), đổi HẲN cách bảng render ẢNH HƯỞNG CẢ DESKTOP dù chỉ định nghĩa 1 cột
                // layout (đã thử và gặp lỗi này ở TenantTable, phải revert). ViewColumn vẫn là Column
                // bình thường nên bảng luôn ở đúng chế độ <table> cổ điển, hiddenFrom/visibleFrom
                // hoạt động đúng như các cột khác bên dưới.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.zone-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                TextColumn::make('name')->label('Tên khu vực')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('buildings_count')->label('Số toà nhà')->counts('buildings')->sortable()->visibleFrom('md'),
                TextColumn::make('note')->label('Ghi chú')->limit(50)->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->visibleFrom('md'),
            ])
            ->filters([
                // Xoá mềm — mặc định chỉ hiện bản ghi CHƯA xoá, chọn "Đã xoá"/"Tất cả" để xem và
                // khôi phục/xoá vĩnh viễn (xem RestoreAction/ForceDeleteAction bên dưới).
                TrashedFilter::make(),
            ])
            ->actions([
                // Ẩn chữ nhãn, chỉ giữ icon, RIÊNG dưới 768px — xem _mobile-action-styles.blade.php.
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                // Xem chú thích ở EditZone::getHeaderActions() — chặn xoá khu vực còn Toà nhà thuộc
                // về nó, tránh Building.zone_id trỏ về 1 Zone đã "biến mất".
                DeleteAction::make()
                    ->extraAttributes(['class' => 'mh-row-action'])
                    ->before(function (DeleteAction $action, Zone $record) {
                        if ($record->buildings()->exists()) {
                            Notification::make()
                                ->title('Không thể xoá')
                                ->body('Khu vực "' . $record->name . '" vẫn còn Toà nhà thuộc về nó — hãy chuyển hoặc xoá các Toà nhà đó trước.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
                RestoreAction::make()->extraAttributes(['class' => 'mh-row-action']),
                // Cùng lý do chặn DeleteAction ở trên — Zone::booted() đã tự chặn ở tầng model
                // (deleting() event vẫn fire khi forceDelete()), ->before() ở đây chỉ để hiện thông
                // báo thân thiện thay vì lỗi chung chung.
                ForceDeleteAction::make()
                    ->extraAttributes(['class' => 'mh-row-action'])
                    ->before(function (ForceDeleteAction $action, Zone $record) {
                        if ($record->buildings()->exists()) {
                            Notification::make()
                                ->title('Không thể xoá vĩnh viễn')
                                ->body('Khu vực "' . $record->name . '" vẫn còn Toà nhà thuộc về nó — hãy chuyển hoặc xoá các Toà nhà đó trước.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->before(function (DeleteBulkAction $action, Collection $records) {
                        $blocked = $records->filter(fn (Zone $zone) => $zone->buildings()->exists());

                        if ($blocked->isNotEmpty()) {
                            Notification::make()
                                ->title('Không thể xoá')
                                ->body('Các khu vực sau vẫn còn Toà nhà thuộc về nó: ' . $blocked->pluck('name')->implode(', ') . '. Hãy chuyển hoặc xoá các Toà nhà đó trước.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make()
                    ->before(function (ForceDeleteBulkAction $action, Collection $records) {
                        $blocked = $records->filter(fn (Zone $zone) => $zone->buildings()->exists());

                        if ($blocked->isNotEmpty()) {
                            Notification::make()
                                ->title('Không thể xoá vĩnh viễn')
                                ->body('Các khu vực sau vẫn còn Toà nhà thuộc về nó: ' . $blocked->pluck('name')->implode(', ') . '.')
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
