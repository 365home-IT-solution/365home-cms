<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TtlockAccountResource\Pages;
use App\Filament\Support\PartnerTableHelpers;
use App\Models\TtlockAccount;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TtlockAccountResource extends Resource
{
    protected static ?string $model = TtlockAccount::class;

    protected static ?string $navigationIcon  = 'heroicon-o-lock-closed';
    protected static ?string $navigationGroup = 'Cấu hình web';
    protected static ?string $navigationLabel = 'Tài khoản TTLock';
    protected static ?string $modelLabel      = 'Tài khoản TTLock';
    protected static ?string $pluralModelLabel = 'Tài khoản TTLock';
    protected static ?int    $navigationSort  = 90;

    // Trước đây hardcode isSuperAdmin() — bỏ qua permission view_any_ttlock::account (đã có sẵn),
    // khiến tick/bỏ tick quyền này ở Roles & Permissions vô tác dụng.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_ttlock::account') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin tài khoản')
                ->schema([
                    TextInput::make('name')
                        ->label('Tên hiển thị')
                        ->placeholder('VD: Chi nhánh Hà Nội')
                        ->required()
                        ->maxLength(100)
                        ->columnSpanFull(),

                    Grid::make(2)->schema([
                        TextInput::make('client_id')
                            ->label('Client ID')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('client_secret')
                            ->label('Client Secret')
                            ->required()
                            ->maxLength(100)
                            ->password()
                            ->revealable(),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('username')
                            ->label('Username (Email TTLock App)')
                            ->required()
                            ->email()
                            ->maxLength(100),

                        TextInput::make('password_md5')
                            ->label('Mật khẩu (MD5)')
                            ->helperText('Nhập chuỗi MD5 của mật khẩu TTLock App (32 ký tự thường)')
                            ->password()
                            ->revealable()
                            ->maxLength(32)
                            ->required(fn ($record) => $record === null),
                    ]),

                    TextInput::make('api_base')
                        ->label('API Base URL')
                        ->default('https://euapi.ttlock.com')
                        ->required()
                        ->url()
                        ->maxLength(200)
                        ->columnSpanFull(),
                ]),

            Section::make('Phân quyền & Trạng thái')
                ->schema([
                    Select::make('categories')
                        ->label('Chi nhánh')
                        ->helperText('Gán tài khoản này cho một hoặc nhiều chi nhánh. Mỗi chi nhánh chỉ dùng được 1 tài khoản TTLock.')
                        ->multiple()
                        ->relationship(
                            'categories',
                            'name',
                            fn ($query) => $query
                                ->where('category_type', 'product')
                                ->orderBy('name')
                        )
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),

                    Toggle::make('is_active')
                        ->label('Kích hoạt')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->color('gray'),

                TextColumn::make('categories.name')
                    ->label('Chi nhánh')
                    ->badge()
                    ->color('info'),

                TextColumn::make('api_base')
                    ->label('API Base')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                PartnerTableHelpers::column(),
            ])
            ->filters([
                PartnerTableHelpers::filter(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('id', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTtlockAccounts::route('/'),
            'create' => Pages\CreateTtlockAccount::route('/create'),
            'edit'   => Pages\EditTtlockAccount::route('/{record}/edit'),
        ];
    }
}
