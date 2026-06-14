<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Promotion\App\Models\Coupon;

class PersonalCouponsRelationManager extends RelationManager
{
    protected static string $relationship = 'personalCoupons';

    protected static ?string $title = 'Coupon cá nhân';

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
                            ? $state . '%'
                            : number_format((float) $state, 0, ',', '.') . ' VNĐ'
                    )
                    ->badge()
                    ->color('success'),

                TextColumn::make('used_count')
                    ->label('Đã dùng / Giới hạn')
                    ->formatStateUsing(fn ($state, Coupon $record): string =>
                        $state . ' / ' . ($record->usage_limit ?? '∞')
                    ),

                TextColumn::make('start_at')
                    ->label('Bắt đầu')
                    ->date('d/m/Y'),

                TextColumn::make('end_at')
                    ->label('Hết hạn')
                    ->date('d/m/Y')
                    ->placeholder('Không giới hạn'),

                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25])
            ->headerActions([])
            ->actions([
                Action::make('toggle')
                    ->label(fn (Coupon $record) => $record->is_active ? 'Vô hiệu' : 'Kích hoạt')
                    ->icon(fn (Coupon $record) => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (Coupon $record) => $record->is_active ? 'danger' : 'success')
                    ->action(fn (Coupon $record) => $record->update(['is_active' => ! $record->is_active])),
            ])
            ->bulkActions([]);
    }
}
