<?php

namespace Modules\Minihouse\App\Filament\Resources\RentalInquiryResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\RentalInquiry;

class RentalInquiryTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label('Họ tên')->searchable(),
                TextColumn::make('phone')->label('Số điện thoại')->searchable(),
                TextColumn::make('room.code')
                    ->label('Quan tâm')
                    ->getStateUsing(fn (RentalInquiry $record) => $record->room
                        ? "Phòng {$record->room->code} — " . ($record->room->building?->name ?? '—')
                        : ($record->building?->name ?? '—')),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => RentalInquiry::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        RentalInquiry::STATUS_NEW       => 'danger',
                        RentalInquiry::STATUS_CONTACTED => 'warning',
                        RentalInquiry::STATUS_CLOSED    => 'success',
                        default                          => 'gray',
                    }),
                TextColumn::make('created_at')->label('Thời điểm gửi')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(RentalInquiry::STATUSES),
            ])
            ->actions([
                EditAction::make()->label('Xử lý'),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100]);
    }
}
