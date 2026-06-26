<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembershipLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'membershipLogs';

    protected static ?string $title = 'Lịch sử hạng thành viên';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('fromTier.name')
                    ->label('Hạng trước')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn ($record) => $record->fromTier?->color ?? 'gray'),

                TextColumn::make('toTier.name')
                    ->label('Hạng mới')
                    ->badge()
                    ->color(fn ($record) => $record->toTier?->color ?? 'gray'),

                TextColumn::make('reason')
                    ->label('Lý do')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'registration'      => 'Đăng ký tài khoản',
                        'spending_upgrade'  => 'Đạt ngưỡng chi tiêu',
                        'manual'            => 'Điều chỉnh thủ công',
                        default             => $state,
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'registration'     => 'success',
                        'spending_upgrade' => 'warning',
                        'manual'           => 'info',
                        default            => 'gray',
                    }),

                TextColumn::make('spending_at_change')
                    ->label('Chi tiêu tại thời điểm')
                    ->money('VND')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
