<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Product\App\Filament\Resources\AdditionServiceResource\Pages;

// Dịch vụ bổ sung được lọc theo partner_id qua BelongsToPartner (global scope trên
// AdditionService model) — mỗi đối tác tự tạo dịch vụ của riêng mình, không dùng chung nữa.
class AdditionServiceResource extends Resource
{
    protected static ?string $model = AdditionService::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-puzzle-piece';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationLabel(): string
    {
        return 'Dịch vụ Bổ sung';
    }

    public static function getModelLabel(): string
    {
        return 'Dịch vụ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Dịch vụ Bổ sung';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('image')
                ->label('Ảnh dịch vụ')
                ->image()
                ->directory('addition-services')
                ->imageEditor()
                ->imageEditorAspectRatios([null, '16:9', '4:3', '1:1'])
                ->imageCropAspectRatio('1:1')
                ->imageResizeMode('cover')
                ->imageResizeTargetWidth('800')
                ->imageResizeTargetHeight('800')
                ->columnSpanFull(),

            TextInput::make('name')
                ->label('Tên dịch vụ')
                ->required()
                ->maxLength(255),

            TextInput::make('price')
                ->label('Đơn giá')
                ->numeric()
                ->prefix('₫')
                ->required()
                ->minValue(0),

            Toggle::make('is_active')
                ->label('Kích hoạt')
                ->default(true)
                ->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width(60),

                ImageColumn::make('image')
                    ->label('Ảnh')
                    ->square()
                    ->size(60),

                TextColumn::make('name')
                    ->label('Tên dịch vụ')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('price')
                    ->label('Đơn giá')
                    ->money('VND')
                    ->sortable(),

                TextColumn::make('products_count')
                    ->label('Số phòng')
                    ->counts('products')
                    ->badge()
                    ->color('info'),

                ToggleColumn::make('is_active')
                    ->label('Kích hoạt'),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                PartnerTableHelpers::column(),
            ])
            ->filters([
                PartnerTableHelpers::filter(),
            ])
            ->defaultSort('id')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAdditionServices::route('/'),
            'create' => Pages\CreateAdditionService::route('/create'),
            'edit'   => Pages\EditAdditionService::route('/{record}/edit'),
        ];
    }
}
