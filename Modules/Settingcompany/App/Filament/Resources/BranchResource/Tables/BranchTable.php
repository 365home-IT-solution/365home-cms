<?php
declare(strict_types=1);
namespace Modules\SettingCompany\App\Filament\Resources\BranchResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\SettingCompany\App\Filament\Resources\BranchResource\Tables\Actions\BranchAction;
use Modules\SettingCompany\App\Filament\Resources\BranchResource\Tables\BulkActions\BranchBulkAction;
use Modules\SettingCompany\App\Filament\Resources\BranchResource\Tables\Filters\BranchFilter;

class BranchTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('settingcompany::branch.table.columns.id'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('settingcompany::branch.table.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('settingcompany::branch.table.columns.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label(__('settingcompany::branch.table.columns.phone'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('address')
                    ->label(__('settingcompany::branch.table.columns.address'))
                    ->searchable()
                    ->sortable(),
                ToggleColumn::make('status')
                    ->label(__('settingcompany::branch.table.columns.status'))
                    ->sortable()
                    ->onColor('success')
                    ->offColor('danger')
                    ->onIcon(__('settingcompany::branch.table.icons.active'))
                    ->offIcon(__('settingcompany::branch.table.icons.inactive'))
                    ->action(function ($record, $state) {
                        $record->status = $state ? '1' : '0';
                        $record->save();
                    }),
            ])
            ->filters(BranchFilter::filter())
            ->actions(BranchAction::action())
            ->bulkActions(BranchBulkAction::bulkActions());
    }
}
