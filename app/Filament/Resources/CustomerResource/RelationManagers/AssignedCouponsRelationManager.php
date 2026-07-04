<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\DetachAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Promotion\App\Models\Coupon;

class AssignedCouponsRelationManager extends RelationManager
{
    protected static string $relationship = 'coupons';

    protected static ?string $title = 'Coupon được gán (hạng thành viên)';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã coupon')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                TextColumn::make('name')
                    ->label('Tên')
                    ->limit(40),

                TextColumn::make('value')
                    ->label('Giá trị')
                    ->formatStateUsing(fn ($state, Coupon $record): string =>
                        $record->type === 'percentage'
                            ? (float) $state . '%'
                            : number_format((float) $state, 0, ',', '.') . ' VNĐ'
                    )
                    ->badge()
                    ->color('info'),

                TextColumn::make('used_count')
                    ->label('Đã dùng / Giới hạn')
                    ->formatStateUsing(fn ($state, Coupon $record): string =>
                        $state . ' / ' . ($record->usage_limit ?? '∞')
                    ),

                TextColumn::make('end_at')
                    ->label('Hết hạn')
                    ->date('d/m/Y')
                    ->placeholder('Không giới hạn'),

                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean(),

                TextColumn::make('pivot.assigned_at')
                    ->label('Ngày gán')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->defaultSort('pivot_assigned_at', 'desc')
            ->paginated([10, 25])
            ->headerActions([])
            ->actions([
                DetachAction::make()->label('Gỡ'),
            ])
            ->bulkActions([]);
    }
}
