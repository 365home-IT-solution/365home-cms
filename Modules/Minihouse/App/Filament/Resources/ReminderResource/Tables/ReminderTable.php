<?php

namespace Modules\Minihouse\App\Filament\Resources\ReminderResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Reminder;

class ReminderTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Tiêu đề')->searchable(),
                TextColumn::make('type')->label('Loại')->formatStateUsing(fn (string $state) => match ($state) {
                    Reminder::TYPE_PAYMENT     => 'Nhắc đóng tiền',
                    Reminder::TYPE_CONTRACT    => 'Nhắc hết hạn hợp đồng',
                    Reminder::TYPE_MAINTENANCE => 'Nhắc bảo trì',
                    default => 'Khác',
                }),
                TextColumn::make('remind_date')->label('Ngày nhắc')->date('d/m/Y')->sortable(),
                TextColumn::make('room.code')->label('Phòng'),
                IconColumn::make('is_done')->label('Đã xử lý')->boolean(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
