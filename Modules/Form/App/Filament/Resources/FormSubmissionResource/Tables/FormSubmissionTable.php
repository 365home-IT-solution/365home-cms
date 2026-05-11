<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormSubmissionResource\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Form\App\Filament\Resources\FormSubmissionResource\Tables\Actions\FormSubmissionAction;
use Modules\Form\App\Filament\Resources\FormSubmissionResource\Tables\BulkActions\FormSubmissionBulkAction;
use Modules\Form\App\Filament\Resources\FormSubmissionResource\Tables\Filters\FormSubmissionFilter;

class FormSubmissionTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('form.name')
                    ->label(__('form::form-submission.table.column.form_name'))
                    ->sortable()
                    ->searchable(),
                IconColumn::make('is_viewed')
                    ->label(__('form::form-submission.table.column.is_viewed'))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                TextColumn::make('viewedByUser.email')
                    ->label(__('form::form-submission.table.column.viewed_by'))
                    ->sortable(),
                TextColumn::make('viewed_at')
                    ->label(__('form::form-submission.table.column.viewed_at'))
                    ->dateTime('d/m/Y H:i:s')
                    ->timezone('Asia/Ho_Chi_Minh'),
                TextColumn::make('created_at')
                    ->label(__('form::form-submission.table.column.created_at'))
                    ->dateTime('d/m/Y H:i:s')
                    ->timezone('Asia/Ho_Chi_Minh'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(FormSubmissionFilter::filter())
            ->actions(FormSubmissionAction::action())
            ->bulkActions(FormSubmissionBulkAction::bulkActions());
    }
}
