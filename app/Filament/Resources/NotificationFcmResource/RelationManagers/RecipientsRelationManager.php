<?php

namespace App\Filament\Resources\NotificationFcmResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Danh sách người nhận';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.fullname')
                    ->label('Họ và tên')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.phone')
                    ->label('Số điện thoại')
                    ->searchable(),

                Tables\Columns\TextColumn::make('fcm_token')
                    ->label('Token thiết bị')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->fcm_token)
                    ->fontFamily('mono')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái gửi')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'sent'   => 'Đã gửi',
                        'failed' => 'Thất bại',
                        default  => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'sent'   => 'success',
                        'failed' => 'danger',
                        default  => 'gray',
                    }),

                Tables\Columns\TextColumn::make('read_status')
                    ->label('Đã xem')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->read_at ? 'read' : 'unread')
                    ->formatStateUsing(fn (string $state) => $state === 'read' ? 'Đã xem' : 'Chưa xem')
                    ->color(fn (string $state) => $state === 'read' ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('read_at')
                    ->label('Thời gian xem')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Thời gian gửi')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated([10, 25, 50]);
    }
}
