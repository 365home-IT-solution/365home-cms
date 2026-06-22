<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GuestCustomerResource\Pages;
use App\Models\GuestCustomer;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class GuestCustomerResource extends Resource
{
    protected static ?string $model = GuestCustomer::class;

    protected static ?string $navigationIcon  = 'heroicon-o-user-circle';
    protected static ?string $navigationLabel = 'Khách vãng lai';
    protected static ?string $navigationGroup = 'Quản lý Khách hàng';
    protected static ?int    $navigationSort  = 10;
    protected static ?string $modelLabel      = 'Khách vãng lai';
    protected static ?string $pluralModelLabel = 'Khách vãng lai';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin cá nhân')->schema([
                Grid::make(2)->schema([
                    TextInput::make('fullname')
                        ->label('Họ và tên')
                        ->disabled(),

                    TextInput::make('phone')
                        ->label('Số điện thoại')
                        ->disabled(),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('province.name')
                        ->label('Khu vực')
                        ->disabled(),

                    TextInput::make('device_token')
                        ->label('Device Token')
                        ->disabled(),
                ]),
            ]),

            Section::make('Tài khoản đã đăng ký')->schema([
                Grid::make(2)->schema([
                    TextInput::make('customer.fullname')
                        ->label('Tên tài khoản')
                        ->disabled()
                        ->placeholder('Chưa đăng ký'),

                    TextInput::make('customer.phone')
                        ->label('SĐT tài khoản')
                        ->disabled()
                        ->placeholder('—'),
                ]),
            ])->collapsible(),

            Section::make('Dữ liệu CCCD')->schema([
                Textarea::make('cccd_data')
                    ->label('Dữ liệu trích xuất')
                    ->disabled()
                    ->rows(6)
                    ->formatStateUsing(fn ($state) => $state
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : null
                    ),
            ])->collapsible()->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('fullname')
                    ->label('Họ và tên')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('province.name')
                    ->label('Khu vực')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('customer.fullname')
                    ->label('Tài khoản')
                    ->placeholder('Chưa đăng ký')
                    ->badge()
                    ->color(fn (GuestCustomer $record): string => $record->customer_id ? 'success' : 'gray'),

                TextColumn::make('device_token')
                    ->label('Device Token')
                    ->limit(20)
                    ->tooltip(fn ($record) => $record->device_token)
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('registered')
                    ->label('Trạng thái đăng ký')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã đăng ký')
                    ->falseLabel('Chưa đăng ký')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('customer_id'),
                        false: fn ($query) => $query->whereNull('customer_id'),
                    ),

                SelectFilter::make('province_id')
                    ->label('Khu vực')
                    ->relationship('province', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGuestCustomers::route('/'),
            'view'  => Pages\ViewGuestCustomer::route('/{record}'),
        ];
    }
}
