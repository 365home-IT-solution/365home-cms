<?php

namespace Modules\Metering\App\Filament\Resources\MeteringReadingResource\Tables;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Modules\Metering\App\Models\MeteringReading;

class MeteringReadingTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('room.code')->label('Phòng')->searchable()->sortable(),
                TextColumn::make('room.building.name')->label('Toà nhà')->sortable(),
                TextColumn::make('month')->label('Tháng')->date('m/Y')->sortable(),
                TextColumn::make('electric_start')->label('Điện đầu kỳ')->numeric(),
                TextColumn::make('electric_end')->label('Điện cuối kỳ')->numeric()->placeholder('Chưa ghi'),
                TextColumn::make('water_start')->label('Nước đầu kỳ')->numeric(),
                TextColumn::make('water_end')->label('Nước cuối kỳ')->numeric()->placeholder('Chưa ghi'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                EditAction::make(),
                // Chặn xoá log ĐÃ dùng để lập hoá đơn — kiểm tra lại đúng điều kiện model đã chặn ở
                // 'deleting' (MeteringReading::isUsedInInvoice()) để hiện thông báo thân thiện TRƯỚC
                // khi gọi delete(), mirror đúng cách InvoiceTable chặn hoá đơn đã có thanh toán duyệt.
                DeleteAction::make()
                    ->before(function (MeteringReading $record, DeleteAction $action) {
                        if ($record->isUsedInInvoice()) {
                            Notification::make()
                                ->title('Không thể xoá')
                                ->body('Log này đã được dùng để lập hoá đơn tháng ' . $record->month->format('m/Y') . ' — hãy xoá hoá đơn đó trước.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->before(function (Collection $records, DeleteBulkAction $action) {
                        $blocked = $records->filter(fn (MeteringReading $record) => $record->isUsedInInvoice());

                        if ($blocked->isNotEmpty()) {
                            Notification::make()
                                ->title('Không thể xoá')
                                ->body('Có ' . $blocked->count() . ' log trong số đã chọn đã được dùng để lập hoá đơn — bỏ chọn rồi thử lại.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->defaultSort('month', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
