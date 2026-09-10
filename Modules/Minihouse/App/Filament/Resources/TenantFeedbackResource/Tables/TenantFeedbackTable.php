<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantFeedbackResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Room;

class TenantFeedbackTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('room.code')->label('Phòng')->placeholder('Chung')->searchable(),
                TextColumn::make('rating')->label('Đánh giá')->formatStateUsing(fn (int $state) => str_repeat('★', $state) . str_repeat('☆', 5 - $state))->sortable(),
                TextColumn::make('tenant_name')->label('Khách')->placeholder('Ẩn danh')->searchable(),
                TextColumn::make('content')->label('Góp ý')->limit(60)->wrap(),
                IconColumn::make('is_reviewed')->label('Đã xử lý')->boolean(),
                TextColumn::make('created_at')->label('Ngày gửi')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('rating')
                    ->label('Đánh giá')
                    ->options([1 => '★', 2 => '★★', 3 => '★★★', 4 => '★★★★', 5 => '★★★★★']),
                SelectFilter::make('room_id')
                    ->label('Phòng')
                    ->options(fn () => Room::query()->pluck('code', 'id')),
                TernaryFilter::make('is_reviewed')
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
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100]);
    }
}
