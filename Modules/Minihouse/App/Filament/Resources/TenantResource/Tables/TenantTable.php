<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Room;

class TenantTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('id_card_front')->label('CCCD')->circular(),
                TextColumn::make('fullname')->label('Họ tên')->searchable()->sortable(),
                TextColumn::make('phone')->label('Điện thoại')->searchable(),
                TextColumn::make('id_card_number')->label('CCCD/CMND')->searchable(),
                TextColumn::make('room.code')->label('Phòng đang ở')->searchable()->sortable(),
                IconColumn::make('residence_declared')->label('Đã khai báo tạm trú')->boolean(),
                TextColumn::make('date_of_birth')->label('Ngày sinh')->date('d/m/Y')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('emergency_contact_phone')->label('SĐT khẩn cấp')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('room_id')
                    ->label('Phòng đang ở')
                    ->options(fn () => Room::query()->pluck('code', 'id')),
                TernaryFilter::make('room_id')
                    ->label('Đang thuê phòng?')
                    ->nullable()
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('room_id'),
                        false: fn ($query) => $query->whereNull('room_id'),
                    ),
                TernaryFilter::make('residence_declared')
                    ->label('Đã khai báo tạm trú?')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã khai báo')
                    ->falseLabel('Chưa khai báo'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
