<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\EmailSettingResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Form\App\Filament\Resources\EmailSettingResource\Tables\Actions\EmailSettingAction;
use Modules\Form\App\Filament\Resources\EmailSettingResource\Tables\BulkActions\EmailSettingBulkAction;
use Modules\Form\App\Filament\Resources\EmailSettingResource\Tables\Filters\EmailSettingFilter;

class EmailSettingTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('form.name')
                    ->label(__('form::email-setting.table.label.form_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('to_email')
                    ->label(__('form::email-setting.table.label.to_email')),
                TextColumn::make('from_email')
                    ->label(__('form::email-setting.table.label.from_email')),
                TextColumn::make('subject')
                    ->label(__('form::email-setting.table.label.subject')),
                TextColumn::make('created_at')
                    ->label(__('form::email-setting.table.label.created_at'))
                    ->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(EmailSettingFilter::filter())
            ->actions(EmailSettingAction::action())
            ->bulkActions(EmailSettingBulkAction::bulkActions());
    }
}
