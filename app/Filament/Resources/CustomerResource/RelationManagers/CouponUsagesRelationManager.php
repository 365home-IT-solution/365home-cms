<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Payment\App\Filament\Resources\OrderResource;
use Modules\Promotion\App\Models\CouponUsage;

class CouponUsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'couponUsages';

    protected static ?string $title = 'Voucher đã sử dụng';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view_customer_voucher_usage') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã voucher')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                TextColumn::make('coupon.name')
                    ->label('Tên')
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('discount_amount')
                    ->label('Đã giảm')
                    ->money('VND')
                    ->placeholder('Không rõ (dữ liệu cũ)'),

                TextColumn::make('order.order_code')
                    ->label('Đơn hàng')
                    ->url(fn (CouponUsage $record): ?string => $record->order_id
                        ? OrderResource::getUrl('edit', ['record' => $record->order_id])
                        : null)
                    ->color('primary')
                    ->placeholder('—'),

                TextColumn::make('used_at')
                    ->label('Thời gian dùng')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('used_at', 'desc')
            ->paginated([10, 25])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
