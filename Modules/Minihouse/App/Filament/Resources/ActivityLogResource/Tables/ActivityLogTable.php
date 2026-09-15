<?php

namespace Modules\Minihouse\App\Filament\Resources\ActivityLogResource\Tables;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\ActivityLog;
use Modules\Minihouse\App\Models\Building;

class ActivityLogTable
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
                    ->view('minihouse::filament.tables.activity-log-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    // 260px (rộng hơn — bảng này KHÔNG có ô chọn nhiều, chỉ 1 nút "Chi tiết").
                    ->extraCellAttributes(['style' => 'max-width: 260px; width: 100%; overflow: hidden;']),

                TextColumn::make('created_at')->label('Thời gian')->dateTime('d/m/Y H:i:s')->sortable()->visibleFrom('md'),
                TextColumn::make('user_name')->label('Người thực hiện')->searchable()->visibleFrom('md'),
                TextColumn::make('action')->label('Hành động')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        ActivityLog::ACTION_CREATED => 'Tạo mới',
                        ActivityLog::ACTION_UPDATED => 'Cập nhật',
                        ActivityLog::ACTION_DELETED => 'Xoá',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        ActivityLog::ACTION_CREATED => 'success',
                        ActivityLog::ACTION_UPDATED => 'info',
                        ActivityLog::ACTION_DELETED => 'danger',
                        default => 'gray',
                    })
                    ->visibleFrom('md'),
                TextColumn::make('subject_type')->label('Đối tượng')
                    ->formatStateUsing(fn (ActivityLog $record) => $record->subjectTypeLabel())
                    ->visibleFrom('md'),
                TextColumn::make('subject_label')->label('Chi tiết')->searchable()->limit(40)->visibleFrom('md'),
                TextColumn::make('building.name')->label('Toà nhà')->searchable()->sortable()->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('building_id')
                    ->label('Toà nhà')
                    ->options(fn () => Building::pluck('name', 'id')),
                SelectFilter::make('action')
                    ->label('Hành động')
                    ->options([
                        ActivityLog::ACTION_CREATED => 'Tạo mới',
                        ActivityLog::ACTION_UPDATED => 'Cập nhật',
                        ActivityLog::ACTION_DELETED => 'Xoá',
                    ]),
                SelectFilter::make('subject_type')
                    ->label('Loại đối tượng')
                    ->options(fn () => ActivityLog::query()->distinct()->pluck('subject_type', 'subject_type')
                        ->mapWithKeys(fn ($value) => [$value => \Modules\Minihouse\App\Support\ActivityLogFormatter::modelLabel($value)])),
                Filter::make('created_at')
                    ->label('Khoảng ngày')
                    ->form([
                        DatePicker::make('from')->label('Từ ngày'),
                        DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                // Ẩn chữ nhãn, chỉ giữ icon, RIÊNG dưới 768px — xem _mobile-action-styles.blade.php.
                Action::make('details')
                    ->label('Chi tiết')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->extraAttributes(['class' => 'mh-row-action'])
                    ->modalHeading(fn (ActivityLog $record) => $record->subjectTypeLabel() . ' — ' . $record->subjectLabel())
                    ->modalContent(fn (ActivityLog $record) => view('minihouse::filament.resources.activity-log.details', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng'),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([25, 50, 100]);
    }
}
