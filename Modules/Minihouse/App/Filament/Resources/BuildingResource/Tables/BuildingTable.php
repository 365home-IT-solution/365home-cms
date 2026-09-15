<?php

namespace Modules\Minihouse\App\Filament\Resources\BuildingResource\Tables;

use Filament\Notifications\Notification;
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
use Modules\Minihouse\App\Models\Zone;

class BuildingTable
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
                    ->view('minihouse::filament.tables.building-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                ImageColumn::make('image')->label('Ảnh')->circular()->visibleFrom('md'),
                TextColumn::make('name')->label('Tên toà nhà')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('zone.name')->label('Khu vực')->badge()->color('gray')->placeholder('—')->sortable()->visibleFrom('md'),
                TextColumn::make('address')->label('Địa chỉ')->searchable()->visibleFrom('md'),
                TextColumn::make('rooms_count')->label('Số phòng')->counts('rooms')->sortable()->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->visibleFrom('md'),
            ])
            ->filters([
                // withoutGlobalScopes() — Zone hiện không có global scope riêng, giữ để nhất quán và
                // an toàn nếu sau này Zone được thêm scope tương tự Building.
                SelectFilter::make('zone_id')
                    ->label('Khu vực')
                    ->options(fn () => Zone::withoutGlobalScopes()->pluck('name', 'id')),
                TrashedFilter::make(),
            ])
            ->actions([
                // Ẩn chữ nhãn, chỉ giữ icon, RIÊNG dưới 768px — xem _mobile-action-styles.blade.php.
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                // Xem chú thích ở EditBuilding::getHeaderActions() — chặn xoá toà nhà còn Phòng thuộc
                // về nó, tránh Room.building_id trỏ về 1 Building đã "biến mất".
                DeleteAction::make()
                    ->extraAttributes(['class' => 'mh-row-action'])
                    ->before(function (DeleteAction $action, Building $record) {
                        if ($record->rooms()->exists()) {
                            Notification::make()
                                ->title('Không thể xoá')
                                ->body('Toà nhà "' . $record->name . '" vẫn còn Phòng thuộc về nó — hãy chuyển hoặc xoá các Phòng đó trước.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
                RestoreAction::make()->extraAttributes(['class' => 'mh-row-action']),
                ForceDeleteAction::make()
                    ->extraAttributes(['class' => 'mh-row-action'])
                    ->before(function (ForceDeleteAction $action, Building $record) {
                        if ($record->rooms()->exists()) {
                            Notification::make()
                                ->title('Không thể xoá vĩnh viễn')
                                ->body('Toà nhà "' . $record->name . '" vẫn còn Phòng thuộc về nó — hãy chuyển hoặc xoá các Phòng đó trước.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->before(function (DeleteBulkAction $action, Collection $records) {
                        $blocked = $records->filter(fn (Building $building) => $building->rooms()->exists());

                        if ($blocked->isNotEmpty()) {
                            Notification::make()
                                ->title('Không thể xoá')
                                ->body('Các toà nhà sau vẫn còn Phòng thuộc về nó: ' . $blocked->pluck('name')->implode(', ') . '. Hãy chuyển hoặc xoá các Phòng đó trước.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make()
                    ->before(function (ForceDeleteBulkAction $action, Collection $records) {
                        $blocked = $records->filter(fn (Building $building) => $building->rooms()->exists());

                        if ($blocked->isNotEmpty()) {
                            Notification::make()
                                ->title('Không thể xoá vĩnh viễn')
                                ->body('Các toà nhà sau vẫn còn Phòng thuộc về nó: ' . $blocked->pluck('name')->implode(', ') . '.')
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
