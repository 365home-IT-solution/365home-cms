<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Coupon\App\Filament\Resources\CouponResource\Tables\Actions\CouponAction;
use Modules\Coupon\App\Filament\Resources\CouponResource\Tables\BulkActions\CouponBulkAction;
use Modules\Coupon\App\Filament\Resources\CouponResource\Tables\Filters\CouponFilter;
use Filament\Tables\Columns\IconColumn;

class CouponTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->badge()
                    ->color('success'),

                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'percentage' => 'Phần trăm',
                        'fixed' => 'Cố định',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'percentage' => 'info',
                        'fixed' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('value')
                    ->label('Giá trị')
                    ->formatStateUsing(fn ($record) =>
                    $record->type === 'percentage'
                        ? $record->value . '%'
                        : number_format($record->value, 0, ',', '.') . ' VNĐ'
                    )
                    ->sortable(),

                TextColumn::make('apply_type')
                    ->label('Phạm vi')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'all_rooms' => 'Tất cả phòng',
                        'specific_room' => 'Phòng cụ thể',
                        'specific_slot' => 'Khung giờ cụ thể',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'all_rooms' => 'success',
                        'specific_room' => 'warning',
                        'specific_slot' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('room.name')
                    ->label('Phòng')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('usage_limit')
                    ->label('Giới hạn')
                    ->formatStateUsing(fn ($state) => $state ?? '∞')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('used_count')
                    ->label('Đã dùng')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($record) =>
                    $record->usage_limit && $record->used_count >= $record->usage_limit
                        ? 'danger'
                        : 'success'
                    ),

                IconColumn::make('is_active')
                    ->label('Trạng thái')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('start_at')
                    ->label('Bắt đầu')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('end_at')
                    ->label('Kết thúc')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('creator.fullname')
                    ->label('Người tạo')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(CouponFilter::filter())
            ->actions(CouponAction::action())
            ->bulkActions(CouponBulkAction::bulkActions());
    }
}