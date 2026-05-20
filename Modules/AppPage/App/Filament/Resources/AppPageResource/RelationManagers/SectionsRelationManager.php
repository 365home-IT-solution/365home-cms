<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Filament\Resources\AppPageResource\RelationManagers;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sections';
    protected static ?string $title = 'Sections';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')
                ->label('Tiêu đề')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set) => $set('key', Str::slug($state, '_'))),

            TextInput::make('key')
                ->label('Key')
                ->required()
                ->maxLength(100)
                ->helperText('Định danh section, VD: section_deals_may'),

            TextInput::make('subtitle')
                ->label('Phụ đề')
                ->maxLength(255),

            Select::make('layout')
                ->label('Layout')
                ->options([
                    'horizontal_scroll' => 'Cuộn ngang',
                    'grid'              => 'Lưới',
                    'featured'          => 'Nổi bật',
                ])
                ->default('horizontal_scroll')
                ->required(),

            TextInput::make('view_all_url')
                ->label('URL "Xem tất cả"')
                ->placeholder('VD: /rooms?section=deals_may')
                ->maxLength(500),

            TextInput::make('sort_order')
                ->label('Thứ tự')
                ->numeric()
                ->default(0),

            Toggle::make('show_arrow')
                ->label('Hiện nút mũi tên')
                ->default(true),

            Toggle::make('is_active')
                ->label('Kích hoạt')
                ->default(true),

            KeyValue::make('filter_config')
                ->label('Bộ lọc phòng (filter_config)')
                ->helperText('VD: room_type_id=1, tags=deal, limit=10, order_by=latest')
                ->keyLabel('Key')
                ->valueLabel('Giá trị')
                ->addButtonLabel('Thêm điều kiện'),

            KeyValue::make('badge_config')
                ->label('Badge section (badge_config)')
                ->helperText('VD: label=Deal, type=deal, bg_color=#FF0000, text_color=#FFFFFF')
                ->keyLabel('Key')
                ->valueLabel('Giá trị')
                ->addButtonLabel('Thêm thuộc tính'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->width(50),

                TextColumn::make('key')
                    ->label('Key')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable(),

                TextColumn::make('layout')
                    ->label('Layout')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'horizontal_scroll' => 'info',
                        'grid'              => 'warning',
                        'featured'          => 'success',
                        default             => 'gray',
                    }),

                ToggleColumn::make('is_active')
                    ->label('Kích hoạt'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([CreateAction::make()])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
