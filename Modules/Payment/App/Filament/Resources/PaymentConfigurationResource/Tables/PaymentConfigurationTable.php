<?php

namespace Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Tables\Actions\PaymentConfigurationAction;
use Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Tables\BulkActions\PaymentConfigurationBulkAction;
use Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Tables\Filters\PaymentConfigurationFilter;

class PaymentConfigurationTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('payment::payment-configuration.table.label.id'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_id')
                    ->label(__('payment::payment-configuration.table.label.client_id'))
                    ->limit(20)
                    ->formatStateUsing(fn($state) => str_repeat('*', strlen($state))),

                TextColumn::make(name: 'api_key')
                    ->label(__('payment::payment-configuration.table.label.api_key'))
                    ->limit(20)
                    ->formatStateUsing(fn($state) => str_repeat('*', strlen($state))),
                TextColumn::make(name: 'checksum_key')
                    ->label(__('payment::payment-configuration.table.label.checksum_key'))
                    ->limit(20)
                    ->formatStateUsing(fn($state) => str_repeat('*', strlen($state))),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters(PaymentConfigurationFilter::filter())
            ->actions(PaymentConfigurationAction::action())
            ->bulkActions(PaymentConfigurationBulkAction::bulkActions());
    }
}
