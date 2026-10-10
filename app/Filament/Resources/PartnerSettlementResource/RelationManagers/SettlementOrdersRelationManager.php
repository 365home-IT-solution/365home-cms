<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerSettlementResource\RelationManagers;

use App\Services\OrderCommissionService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Payment\Entities\Order;

// Các đơn trong bảng đối soát — kèm tiền về đâu, hoa hồng, khoản 365home bù và từng khoản giảm kèm người chịu.
class SettlementOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Các đơn trong kỳ';

    public function table(Table $table): Table
    {
        $money = fn ($state) => number_format((int) $state, 0, ',', '.') . 'đ';
        $commission = app(OrderCommissionService::class);

        return $table
            ->recordTitleAttribute('order_code')
            ->modifyQueryUsing(fn ($query) => $query->with('items'))
            ->columns([
                Tables\Columns\TextColumn::make('order_code')->label('Mã đơn')->searchable(),
                Tables\Columns\TextColumn::make('buyer_name')->label('Khách'),
                Tables\Columns\TextColumn::make('rooms')->label('Phòng')->wrap()
                    ->state(fn (Order $record) => $record->items->pluck('name')->filter()->unique()->implode(', ') ?: '—'),
                Tables\Columns\TextColumn::make('stay')->label('Lưu trú')->wrap()
                    ->state(function (Order $record) {
                        $item = $record->items->first(fn ($i) => $i->checkin_date && $i->checkout_date);

                        return $item ? $item->checkin_date->format('d/m/Y H:i') . ' → ' . $item->checkout_date->format('d/m/Y H:i') : '—';
                    }),
                Tables\Columns\TextColumn::make('collected_by')->label('Tiền về')->badge()->formatStateUsing(fn (?string $state) => OrderCommissionService::COLLECTED_BY[$state] ?? '—'),
                Tables\Columns\TextColumn::make('revenue')->label('Khách trả')->state(fn (Order $record) => $commission->retainedAmount($record))->formatStateUsing($money)->alignEnd(),
                Tables\Columns\TextColumn::make('commission_rate')->label('Tỉ lệ')->suffix('%')->alignEnd(),
                Tables\Columns\TextColumn::make('commission_amount')->label('Hoa hồng')->formatStateUsing($money)->alignEnd(),
                Tables\Columns\TextColumn::make('platform_subsidy')->label('365home bù')->formatStateUsing($money)->alignEnd(),
                Tables\Columns\TextColumn::make('discounts')->label('Khoản giảm (người chịu)')->wrap()
                    ->state(fn (Order $record) => collect($record->discounts ?? [])->map(function (array $line) {
                        $who = ['partner' => 'đối tác', 'platform' => '365home', 'shared' => 'đồng tài trợ'][$line['funded_by'] ?? 'partner'] ?? '';
                        $label = ['coupon' => 'Mã ' . ($line['code'] ?? ''), 'promotion' => 'Khuyến mãi phòng', 'system' => 'Chiết khấu hệ thống'][$line['source'] ?? ''] ?? ($line['source'] ?? '');

                        return "{$label}: " . number_format((int) ($line['amount'] ?? 0), 0, ',', '.') . "đ ({$who})";
                    })->implode("\n")),
            ])
            ->paginated([10, 25, 50]);
    }
}
