<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AskUserResource\Pages;
use App\Models\AskUser;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AskUserResource extends Resource
{
    protected static ?string $model = AskUser::class;

    protected static ?string $navigationIcon   = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Thông báo vào app';
    protected static ?string $modelLabel       = 'Thông báo';
    protected static ?string $pluralModelLabel = 'Thông báo vào app';
    protected static ?int    $navigationSort   = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Nội dung thông báo')->schema([
                TextInput::make('title')
                    ->label('Tiêu đề chính')
                    ->required()
                    ->maxLength(255),

                TextInput::make('description')
                    ->label('Tiêu đề phụ')
                    ->maxLength(500)
                    ->nullable(),

                Repeater::make('items')
                    ->label('Danh sách nội dung')
                    ->schema([
                        FileUpload::make('image')
                            ->label('Hình ảnh')
                            ->image()
                            ->directory('ask-users')
                            ->nullable()
                            ->columnSpanFull(),

                        TextInput::make('title')
                            ->label('Tiêu đề')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->addActionLabel('+ Thêm nội dung')
                    ->reorderable()
                    ->reorderableWithDragAndDrop()
                    ->collapsible()
                    ->collapsed()
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Chưa có tiêu đề')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Tiêu đề chính')
                    ->searchable()
                    ->limit(60),

                TextColumn::make('description')
                    ->label('Tiêu đề phụ')
                    ->limit(60),

                TextColumn::make('items')
                    ->label('Số mục')
                    ->formatStateUsing(fn ($state) => count((array) $state) . ' mục'),

                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('id')
            ->actions([
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAskUsers::route('/'),
            'create' => Pages\CreateAskUser::route('/create'),
            'edit'   => Pages\EditAskUser::route('/{record}/edit'),
        ];
    }
}
