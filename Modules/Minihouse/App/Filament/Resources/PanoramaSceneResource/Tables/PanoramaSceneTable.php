<?php

namespace Modules\Minihouse\App\Filament\Resources\PanoramaSceneResource\Tables;

use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\PanoramaScene;

class PanoramaSceneTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — cùng cơ chế đã áp
                // dụng cho toàn bộ bảng khác trong module (xem TenantTable::table() để biết đầy đủ lý
                // do dùng ViewColumn thay vì Tables\Columns\Layout\*).
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.panorama-scene-mobile-row')
                    ->hiddenFrom('md')
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                ImageColumn::make('thumbnail_path')
                    ->label('Ảnh')
                    ->getStateUsing(fn (PanoramaScene $record) => $record->thumbnail_path ?? $record->image_path)
                    ->visibleFrom('md'),
                TextColumn::make('building.name')->label('Toà nhà')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('title')->label('Tên điểm')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('room.code')->label('Phòng')->placeholder('— (điểm chung)')->searchable()->visibleFrom('md'),
                TextColumn::make('floor')->label('Tầng')->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
                TextColumn::make('hotspots_count')->label('Số điểm nóng')->counts('hotspots')->visibleFrom('md'),
                IconColumn::make('is_published')->label('Công khai')->boolean()->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('building_id')
                    ->label('Toà nhà')
                    ->options(fn () => Building::pluck('name', 'id')),
            ])
            // ĐÃ THỬ dùng Filament ->groups() với khoá gộp tự chế (building_id+room_id) nhưng khiến
            // Livewire render bảng RỖNG (khoá không phải cột SQL thật, vỡ khi Filament tính lại nhóm
            // qua AJAX) — bỏ hẳn, quay về bảng phẳng nhưng sắp xếp CỐ ĐỊNH theo building_id rồi
            // sort_order (xem defaultSort bên dưới) để các dòng CÙNG 1 toà + CÙNG 1 phòng tự nhiên
            // nằm liền kề nhau — đọc vẫn ra đúng thứ tự Toà -> Phòng dù không có khung thu gọn.
            ->actions([
                // Ẩn chữ nhãn, chỉ giữ icon, RIÊNG dưới 768px — xem _mobile-action-styles.blade.php.
                Action::make('preview')
                    ->label('Xem thử')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->extraAttributes(['class' => 'mh-row-action'])
                    ->url(fn (PanoramaScene $record) => route('minihouse.tour.scene', ['building' => $record->building_id, 'scene' => $record->id]))
                    ->openUrlInNewTab(),
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                DeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            // defaultSort() của Filament chỉ nhận ĐÚNG 1 cột — dùng modifyQueryUsing() để sắp theo cả
            // building_id RỒI MỚI tới sort_order, cho các dòng cùng toà/cùng phòng tự nhiên đứng
            // liền kề nhau (đọc ra đúng thứ tự Toà -> Phòng dù bảng vẫn phẳng, không cần Group).
            ->modifyQueryUsing(fn ($query) => $query->orderBy('building_id')->orderBy('sort_order'))
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
