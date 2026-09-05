<?php

namespace Modules\Minihouse\App\Filament\Resources\ReminderResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Reminder;

class ReminderTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Tiêu đề')->searchable()->sortable(),
                TextColumn::make('type')->label('Loại')->formatStateUsing(fn (string $state) => match ($state) {
                    Reminder::TYPE_PAYMENT     => 'Nhắc đóng tiền',
                    Reminder::TYPE_CONTRACT    => 'Nhắc hết hạn hợp đồng',
                    Reminder::TYPE_MAINTENANCE => 'Nhắc bảo trì',
                    default => 'Khác',
                }),
                TextColumn::make('remind_date')->label('Ngày nhắc')->date('d/m/Y')->sortable(),
                TextColumn::make('room.code')->label('Phòng')->searchable(),
                IconColumn::make('is_done')->label('Đã xử lý')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại')
                    ->options([
                        Reminder::TYPE_PAYMENT     => 'Nhắc đóng tiền',
                        Reminder::TYPE_CONTRACT    => 'Nhắc hết hạn hợp đồng',
                        Reminder::TYPE_MAINTENANCE => 'Nhắc bảo trì',
                        Reminder::TYPE_OTHER       => 'Khác',
                    ]),
                TernaryFilter::make('is_done')
                    ->label('Trạng thái xử lý')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã xử lý')
                    ->falseLabel('Chưa xử lý'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('remind_date')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
