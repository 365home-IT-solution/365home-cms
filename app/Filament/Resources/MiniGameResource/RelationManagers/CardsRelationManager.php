<?php

declare(strict_types=1);

namespace App\Filament\Resources\MiniGameResource\RelationManagers;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

// Các lá bài của 1 mini game. Lá mới / lá vừa bật có mặt từ lần trộn kế tiếp của khách; ván đang chơi dở giữ bộ bài cũ.
class CardsRelationManager extends RelationManager
{
    protected static string $relationship = 'cards';

    protected static ?string $title = 'Lá bài';

    protected static ?string $modelLabel = 'lá bài';

    public function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('image')
                ->label('Ảnh lá bài (mặt trước)')
                ->image()
                ->disk('public')
                ->directory('mini-games/cards')
                ->maxSize(5120)
                ->required()
                ->columnSpanFull(),

            TextInput::make('title')
                ->label('Tên lá')
                ->required()
                ->maxLength(255),

            TextInput::make('subtitle')
                ->label('Mô tả ngắn')
                ->maxLength(255),

            Toggle::make('is_active')
                ->label('Đang bật')
                ->helperText('Tắt thì lá này không được chia ở các lần trộn sau.')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                ImageColumn::make('image')
                    ->label('Ảnh')
                    ->disk('public')
                    ->height(80),

                TextColumn::make('title')
                    ->label('Tên lá')
                    ->searchable(),

                TextColumn::make('subtitle')
                    ->label('Mô tả ngắn')
                    ->placeholder('—'),

                ToggleColumn::make('is_active')
                    ->label('Đang bật'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                CreateAction::make()->label('Thêm lá bài'),
            ])
            ->actions([
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá'),
            ])
            ->bulkActions([]);
    }
}
