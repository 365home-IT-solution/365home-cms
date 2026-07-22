<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomHousekeepingResource\Pages;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions\RoomCleaningAction;
use Modules\Product\App\Filament\Resources\RoomHousekeepingResource;
use Modules\Product\App\Models\Product;

class ListRoomHousekeeping extends ListRecords
{
    protected static string $resource = RoomHousekeepingResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên phòng')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('categories.name')
                    ->label('Chi nhánh')
                    ->searchable(),

                TextColumn::make('housekeeping_status')
                    ->label('Tình trạng phòng')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cleaning'    => 'Đang dọn vệ sinh',
                        'maintenance' => 'Đang bảo trì',
                        default       => 'Sẵn sàng',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'cleaning'    => 'warning',
                        'maintenance' => 'danger',
                        default       => 'success',
                    })
                    ->sortable(),

                TextColumn::make('lastCleaningEmployee')
                    ->label('Người dọn gần nhất')
                    ->getStateUsing(function (Product $record): string {
                        $log = $record->cleaningLogs()->whereNotNull('cleaned_at')->first();

                        return $log?->employee?->name ?? '—';
                    }),

                TextColumn::make('lastCleanedAt')
                    ->label('Dọn xong lúc')
                    ->getStateUsing(function (Product $record): ?string {
                        $log = $record->cleaningLogs()->whereNotNull('cleaned_at')->first();

                        return $log?->cleaned_at?->format('H:i d/m/Y');
                    })
                    ->placeholder('—'),

                PartnerTableHelpers::column(),
            ])
            ->filters([
                SelectFilter::make('housekeeping_status')
                    ->label('Tình trạng phòng')
                    ->options([
                        'available'   => 'Sẵn sàng',
                        'cleaning'    => 'Đang dọn vệ sinh',
                        'maintenance' => 'Đang bảo trì',
                    ]),
                PartnerTableHelpers::filter(),
            ])
            ->actions([
                RoomCleaningAction::confirmCleaning(),
                RoomCleaningAction::markForCleaning(),
                Action::make('viewHistory')
                    ->label('Xem lịch sử')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalWidth(MaxWidth::Large)
                    ->modalHeading(fn (Product $record) => "Lịch sử dọn vệ sinh — {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (Product $record) => new HtmlString(self::renderHistory($record))),
            ])
            ->defaultSort('housekeeping_status', 'desc');
    }

    private static function renderHistory(Product $record): string
    {
        $logs = $record->cleaningLogs()->with('employee')->limit(20)->get();

        if ($logs->isEmpty()) {
            return '<div class="text-sm text-gray-500 py-4">Chưa có lượt dọn vệ sinh nào được ghi nhận.</div>';
        }

        $rows = $logs->map(function ($log) {
            $status = $log->isCleaned()
                ? '<span class="text-success-600 font-medium">Đã dọn xong</span>'
                : '<span class="text-warning-600 font-medium">Đang chờ dọn</span>';

            $employee  = e($log->employee?->name ?? '—');
            $markedAt  = e($log->marked_for_cleaning_at?->format('H:i d/m/Y') ?? '—');
            $cleanedAt = e($log->cleaned_at?->format('H:i d/m/Y') ?? '—');
            $note      = e($log->note ?? '');

            return <<<HTML
                <tr class="border-b">
                    <td class="py-2 pr-3">{$markedAt}</td>
                    <td class="py-2 pr-3">{$cleanedAt}</td>
                    <td class="py-2 pr-3">{$employee}</td>
                    <td class="py-2 pr-3">{$status}</td>
                    <td class="py-2 text-gray-500">{$note}</td>
                </tr>
            HTML;
        })->implode('');

        return <<<HTML
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="border-b font-medium text-gray-600">
                            <th class="py-2 pr-3">Bắt đầu dọn</th>
                            <th class="py-2 pr-3">Dọn xong</th>
                            <th class="py-2 pr-3">Nhân viên</th>
                            <th class="py-2 pr-3">Trạng thái</th>
                            <th class="py-2">Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        HTML;
    }
}
