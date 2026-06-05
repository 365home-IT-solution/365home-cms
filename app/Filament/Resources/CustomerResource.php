<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon   = 'heroicon-o-users';
    protected static ?string $navigationGroup  = 'Phân quyền';
    protected static ?string $navigationLabel  = 'Khách hàng';
    protected static ?string $modelLabel       = 'Khách hàng';
    protected static ?string $pluralModelLabel = 'Khách hàng';
    protected static ?int    $navigationSort   = 20;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin cơ bản')->schema([
                FileUpload::make('avatar')
                    ->label('Ảnh đại diện')
                    ->image()
                    ->disk('public')
                    ->directory('avatars')
                    ->maxSize(5120)
                    ->imagePreviewHeight('120')
                    ->avatar()
                    ->inlineLabel(),

                TextInput::make('fullname')
                    ->label('Họ và tên')
                    ->maxLength(255)
                    ->inlineLabel(),

                TextInput::make('phone')
                    ->label('Số điện thoại')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(20)
                    ->inlineLabel(),

                DatePicker::make('date_of_birth')
                    ->label('Ngày sinh')
                    ->displayFormat('d/m/Y')
                    ->inlineLabel(),

                Toggle::make('phone_verified_at')
                    ->label('Đã xác thực SĐT')
                    ->onIcon('heroicon-o-check')
                    ->offIcon('heroicon-o-x-mark')
                    ->formatStateUsing(fn ($record) => ! is_null($record?->phone_verified_at))
                    ->dehydrated(false)
                    ->inlineLabel(),
            ])->columns(1),

            Section::make('CCCD / CMND')->schema([
                FileUpload::make('cccd_front')
                    ->label('Mặt trước CCCD')
                    ->image()
                    ->disk('public')
                    ->directory('cccd')
                    ->maxSize(5120)
                    ->imagePreviewHeight('160')
                    ->inlineLabel(),

                FileUpload::make('cccd_back')
                    ->label('Mặt sau CCCD')
                    ->image()
                    ->disk('public')
                    ->directory('cccd')
                    ->maxSize(5120)
                    ->imagePreviewHeight('160')
                    ->inlineLabel(),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fullname')
                    ->label('Họ và tên')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('date_of_birth')
                    ->label('Ngày sinh')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                IconColumn::make('phone_verified_at')
                    ->label('Đã xác thực')
                    ->boolean()
                    ->getStateUsing(fn ($record) => ! is_null($record->phone_verified_at)),

                IconColumn::make('cccd_front')
                    ->label('CCCD')
                    ->boolean()
                    ->getStateUsing(fn ($record) => ! is_null($record->cccd_front)),

                TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->label('Đã xoá')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions([
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá'),
                RestoreAction::make()->label('Khôi phục'),
                ForceDeleteAction::make()->label('Xoá vĩnh viễn'),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit'   => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
