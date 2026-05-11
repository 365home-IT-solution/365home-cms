<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormResource\Tables;

use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Form\App\Filament\Resources\FormResource\Tables\Actions\FormAction;
use Modules\Form\App\Filament\Resources\FormResource\Tables\BulkActions\FormBulkAction;
use Modules\Form\App\Filament\Resources\FormResource\Tables\Filters\FormFilter;

class FormTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('form::form.table.label.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('form::form.table.label.created_at'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters(FormFilter::filter())
            ->actions([
                Action::make('emailSetting')
                    ->label(__('form::form.table.actions.emailSetting.label'))
                    ->icon(__('form::form.table.actions.emailSetting.icon'))
                    ->color('primary')
                    ->url(fn($record) => route('filament.admin.resources.email-settings.create', ['form_id' => $record->id]))
                    ->openUrlInNewTab()
                    ->button()
                    ->visible(fn($record) => $record->emailSetting === null),
                ...(FormAction::action()),
            ])
            ->defaultSort('created_at', 'desc')
            ->bulkActions(FormBulkAction::bulkActions());
    }
}
