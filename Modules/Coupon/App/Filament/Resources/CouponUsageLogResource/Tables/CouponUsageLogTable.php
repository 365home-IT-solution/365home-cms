<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponUsageLogResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Promotion\App\Models\CouponUsageLog;

class CouponUsageLogTable
{
    private const PAYMENT_METHOD_LABELS = [
        'PayOS' => 'Chuyển khoản (PayOS)',
        'cod'   => 'Tiền mặt',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('used_at')
                    ->label('Thời gian dùng')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->width('150px'),

                TextColumn::make('code')
                    ->label('Mã')
                    ->description(fn ($record) => $record->coupon_name)
                    ->searchable()
                    ->weight(FontWeight::Medium),

                TextColumn::make('order_code')
                    ->label('Đơn hàng')
                    ->searchable()
                    ->url(fn ($record) => $record->order_id
                        ? \Modules\Payment\App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $record->order_id])
                        : null),

                TextColumn::make('customer_name')
                    ->label('Khách hàng')
                    ->description(fn ($record) => $record->customer_phone)
                    ->searchable(['customer_name', 'customer_phone']),

                TextColumn::make('discount_amount')
                    ->label('Số tiền giảm')
                    ->numeric()
                    ->money('VND')
                    ->sortable(),

                TextColumn::make('order_amount')
                    ->label('Giá trị đơn')
                    ->numeric()
                    ->money('VND')
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->label('Thanh toán')
                    ->formatStateUsing(fn ($state) => self::PAYMENT_METHOD_LABELS[$state] ?? $state),

                BadgeColumn::make('reversed_at')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn ($state) => $state ? 'Đã hoàn' : 'Đã dùng')
                    ->colors([
                        'success' => fn ($state) => ! $state,
                        'danger'  => fn ($state) => (bool) $state,
                    ]),

                PartnerTableHelpers::column(),
            ])
            ->filters([
                PartnerTableHelpers::filter(),

                SelectFilter::make('payment_method')
                    ->label('Thanh toán')
                    ->options(self::PAYMENT_METHOD_LABELS)
                    ->placeholder('Tất cả'),

                SelectFilter::make('code')
                    ->label('Mã giảm giá')
                    ->options(fn () => CouponUsageLog::query()
                        ->select('code')
                        ->distinct()
                        ->orderBy('code')
                        ->pluck('code', 'code')
                        ->all())
                    ->searchable()
                    ->placeholder('Tất cả'),

                Filter::make('used_at')
                    ->label('Khoảng thời gian')
                    ->form([
                        DatePicker::make('from')->label('Từ ngày')->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Đến ngày')->displayFormat('d/m/Y'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $v) => $q->whereDate('used_at', '>=', $v))
                            ->when($data['until'], fn ($q, $v) => $q->whereDate('used_at', '<=', $v));
                    }),
            ])
            ->defaultSort('used_at', 'desc');
    }
}
