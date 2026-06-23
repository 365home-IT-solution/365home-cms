<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProvinceResource\RelationManagers;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WardsRelationManager extends RelationManager
{
    protected static string $relationship = 'wards';

    protected static ?string $title = 'Phường / Xã';

    protected static ?string $label = 'phường/xã';

    protected static ?string $pluralLabel = 'Phường / Xã';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Tên')
                ->required()
                ->maxLength(255),

            Select::make('division_type')
                ->label('Loại đơn vị')
                ->options([
                    'phường'  => 'Phường',
                    'xã'      => 'Xã',
                    'đặc khu' => 'Đặc khu',
                ])
                ->required(),

            TextInput::make('codename')
                ->label('Codename')
                ->required()
                ->maxLength(100),

            TextInput::make('code')
                ->label('Mã')
                ->numeric()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('name')
                    ->label('Tên phường/xã')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('division_type')
                    ->label('Loại')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'phường'  => 'info',
                        'xã'      => 'success',
                        'đặc khu' => 'warning',
                        default   => 'gray',
                    }),

                TextColumn::make('codename')
                    ->label('Codename')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('division_type')
                    ->label('Loại đơn vị')
                    ->options([
                        'phường'  => 'Phường',
                        'xã'      => 'Xã',
                        'đặc khu' => 'Đặc khu',
                    ]),
            ])
            ->defaultSort('name')
            ->actions([
                EditAction::make()->label('Sửa'),
            ])
            ->bulkActions([])
            ->paginated([25, 50, 100]);
    }
}
