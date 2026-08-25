<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\User\App\Filament\Resources\UserResource\Tables\Actions\UserAction;
use Modules\User\App\Filament\Resources\UserResource\Tables\BulkAction\UserBulkAction;
use Modules\User\App\Filament\Resources\UserResource\Tables\Filters\UserFilter;
use Illuminate\Support\Str;

class UserTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('media')
                    ->label(__('user::user.table.label.avatars'))
                    ->collection('avatars')
                    ->circular()
                    ->wrap(),
                TextColumn::make('fullname')
                    ->label(__('user::user.table.label.fullname'))
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('roles.name')
                    ->label(__('user::user.table.label.roles'))
                    ->searchable()
                    ->formatStateUsing(fn($state): string => Str::headline($state))
                    ->colors(['info'])
                    ->badge(),
                TextColumn::make('email')
                    ->label(__('user::user.table.label.email'))
                    ->searchable(),
                TextColumn::make('email_verified_at')
                    ->label(__('user::user.table.label.verified_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('user::user.table.label.created_at'))
                    ->dateTime()
                    ->sortable(),
                PartnerTableHelpers::column(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                ...UserFilter::filter(),
                PartnerTableHelpers::filter(),
            ])
            ->actions(UserAction::action())
            ->bulkActions(UserBulkAction::bulkActions());
    }
}
