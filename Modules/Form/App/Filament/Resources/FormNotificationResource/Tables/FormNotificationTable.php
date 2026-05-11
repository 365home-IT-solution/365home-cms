<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormNotificationResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Form\App\Filament\Resources\FormNotificationResource\Tables\Actions\FormNotificationAction;
use Modules\Form\App\Filament\Resources\FormNotificationResource\Tables\BulkActions\FormNotificationBulkAction;
use Modules\Form\App\Filament\Resources\FormNotificationResource\Tables\Filters\FormNotificationFilter;

class FormNotificationTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('form.name')
                    ->label(__('form::form-notification.table.label.form_name'))
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('success_message')
                    ->label(__('form::form-notification.table.label.success_message'))
                    ->wrap()
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('error_message')
                    ->label(__('form::form-notification.table.label.error_message'))
                    ->wrap()
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label(__('form::form-notification.table.label.created_at'))
                    ->dateTime()
                    ->sortable(),
            
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(FormNotificationFilter::filter())
            ->actions(FormNotificationAction::action())
            ->bulkActions(FormNotificationBulkAction::bulkActions());
    }
}