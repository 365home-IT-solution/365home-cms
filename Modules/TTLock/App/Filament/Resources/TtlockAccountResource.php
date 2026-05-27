<?php

declare(strict_types=1);

namespace Modules\TTLock\App\Filament\Resources;

use Modules\TTLock\App\Filament\Resources\TtlockAccountResource\Pages;
use Modules\TTLock\Entities\TtlockAccount;
use Illuminate\Database\Eloquent\Builder;
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
    protected static ?string $navigationGroup = 'Cấu hình thông tin';
    protected static ?string $navigationLabel = 'Tài khoản TTLock';
    protected static ?string $modelLabel      = 'Tài khoản TTLock';
    protected static ?string $pluralModelLabel = 'Tài khoản TTLock';
    protected static ?int    $navigationSort  = 90;

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }
        return ! empty($user->allowedBranchIds());
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $branchIds = $user->allowedBranchIds();

        if (empty($branchIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'categories',
            fn ($q) => $q->whereIn('categories.id', $branchIds)
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                // ── Cột trái: thông tin chính (2/3) ──────────────────
                Section::make('Thông tin tài khoản TTLock')
                    ->description('Thông tin xác thực từ TTLock Open Platform')
                    ->icon('heroicon-o-lock-closed')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên tài khoản')
                            ->placeholder('VD: Chi nhánh Hà Nội')
                            ->required()
                            ->maxLength(100)
                            ->columnSpanFull(),

                        TextInput::make('client_id')
                            ->label('Client ID')
                            ->placeholder('Từ TTLock Open Platform')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('client_secret')
                            ->label('Client Secret')
                            ->required()
                            ->maxLength(100)
                            ->password()
                            ->revealable(),

                        TextInput::make('username')
                            ->label('Username')
                            ->placeholder('Email tài khoản TTLock App')
                            ->required()
                            ->email()
                            ->maxLength(100),

                        TextInput::make('password_md5')
                            ->label('Password (MD5)')
                            ->placeholder('32 ký tự MD5 lowercase')
                            ->helperText('MD5 của mật khẩu TTLock App')
                            ->password()
                            ->revealable()
                            ->maxLength(32)
                            ->required(fn ($record) => $record === null),

                        TextInput::make('api_base')
                            ->label('API Base URL')
                            ->default('https://euapi.ttlock.com')
                            ->required()
                            ->url()
                            ->maxLength(200)
                            ->columnSpanFull(),
                    ]),

                // ── Cột phải: cài đặt (1/3) ──────────────────────────
                Section::make('Cài đặt')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('categories')
                            ->label('Chi nhánh')
                            ->helperText('Mỗi chi nhánh chỉ gán được 1 tài khoản TTLock.')
                            ->multiple()
                            ->relationship(
                                'categories',
                                'name',
                                function ($query) {
                                    $query->where('category_type', 'product')
                                        ->orderBy('name');

                                    $user = auth()->user();
                                    if ($user && ! $user->isSuperAdmin()) {
                                        $query->whereIn('id', $user->allowedBranchIds());
                                    }

                                    return $query;
                                }
                            )
                            ->searchable()
                            ->preload(),

                        Toggle::make('is_active')
                            ->label('Kích hoạt')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('danger'),
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
