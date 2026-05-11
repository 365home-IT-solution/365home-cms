<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables;

use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\Actions\AccessCodeAction;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\Actions\GenerateTTLockPasscodesAction;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\BulkActions\AccessCodeBulkAction;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\Filters\AccessCodeFilter;
use Modules\Payment\App\Filament\Resources\OrderResource;

class AccessCodeTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->colors([
                        'success' => 'active',
                        'danger' => 'disabled',
                        'warning' => 'expired',
                    ])
                    ->formatStateUsing(fn($state) => match ($state) {
                        'active' => 'Hoạt động',
                        'disabled' => 'Đã dừng',
                        'expired' => 'Hết hạn',
                    }),
                TextColumn::make('usage_count')
                    ->label('Số lần đã dùng')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('category.name')
                    ->label('Chi nhánh')
                    ->badge()
                    ->searchable(),

                TextColumn::make('valid_from')
                    ->label('Hiệu lực từ')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('valid_until')
                    ->label('Hết hạn')
                    ->dateTime('d/m/Y H:i')
                    ->color(fn ($record) => $record->valid_until && $record->valid_until->isPast() 
                        ? 'danger' 
                        : 'success'),

                // TextColumn::make('used_at')
                //     ->label('Đã dùng lúc')
                //     ->dateTime('d/m/Y H:i')
                //     ->sortable()
                //     ->alignCenter()
                //     ->placeholder('-- chưa sử dụng --')
                //     ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('gate_location')
                    ->label('Vị trí cổng')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ttlock_keyboard_pwd_id')
                    ->label('TTLock ID (checkin)')
                    ->placeholder('Chưa cấp')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ttlock_keyboard_pwd_id_checkout')
                    ->label('TTLock ID (checkout)')
                    ->placeholder('Chưa cấp')
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('Ghi chú')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                GenerateTTLockPasscodesAction::make(),
            ])
            ->filters(AccessCodeFilter::filter())
            ->actions(AccessCodeAction::action())
            ->bulkActions(AccessCodeBulkAction::bulkActions());
    }
}
