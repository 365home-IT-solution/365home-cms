<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MiniGameResource\Pages;
use App\Filament\Resources\MiniGameResource\RelationManagers\CardsRelationManager;
use AmidEsfahani\FilamentTinyEditor\TinyEditor;
use App\Models\MiniGame;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Quản lý mini game trên app và các lá bài của từng game (tab "Lá bài" ở màn sửa) — API ở App\Http\Controllers\Api\MiniGameController.
class MiniGameResource extends Resource
{
    protected static ?string $model = MiniGame::class;

    protected static ?string $navigationIcon   = 'heroicon-o-puzzle-piece';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Mini game';
    protected static ?string $modelLabel       = 'Mini game';
    protected static ?string $pluralModelLabel = 'Mini game';
    protected static ?int    $navigationSort   = 30;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_mini::game') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin game')->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label('Tên game')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('code')
                        ->label('Mã game')
                        ->helperText('App gọi API theo mã này (/api/mini-games/{mã}). Đổi mã khi app đang dùng sẽ làm app không tìm thấy game.')
                        ->required()
                        ->alphaDash()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true),
                ]),

                Textarea::make('description')
                    ->label('Mô tả ngắn')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),

                TinyEditor::make('long_description')
                    ->label('Mô tả chi tiết & hướng dẫn chơi')
                    ->helperText('Hiển thị ở màn game trên app.')
                    ->id('mini_game_long_description')
                    ->nullable()
                    ->profile('custom-full')
                    ->columnSpanFull(),

                FileUpload::make('thumbnail')
                    ->label('Ảnh đại diện')
                    ->image()
                    ->disk('public')
                    ->directory('mini-games')
                    ->maxSize(5120)
                    ->columnSpanFull(),

                Grid::make(2)->schema([
                    TextInput::make('sort_order')
                        ->label('Thứ tự hiển thị')
                        ->numeric()
                        ->default(0)
                        ->required(),

                    Toggle::make('is_active')
                        ->label('Đang bật')
                        ->helperText('Tắt thì game không hiện trên app và không chơi được.')
                        ->default(true)
                        ->inline(false),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Ảnh')
                    ->disk('public')
                    ->height(48),

                TextColumn::make('name')
                    ->label('Tên game')
                    ->searchable(),

                TextColumn::make('code')
                    ->label('Mã game')
                    ->copyable(),

                TextColumn::make('cards_count')
                    ->label('Số lá bài')
                    ->counts('cards'),

                IconColumn::make('is_active')
                    ->label('Đang bật')
                    ->boolean(),

                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá'),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            CardsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMiniGames::route('/'),
            'create' => Pages\CreateMiniGame::route('/create'),
            'edit'   => Pages\EditMiniGame::route('/{record}/edit'),
        ];
    }
}
