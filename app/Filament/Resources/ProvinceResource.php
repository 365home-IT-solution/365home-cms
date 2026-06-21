<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProvinceResource\Pages;
use App\Models\Province;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;

class ProvinceResource extends Resource
{
    protected static ?string $model = Province::class;

    protected static ?string $navigationIcon   = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup  = 'Cấu hình web';
    protected static ?string $navigationLabel  = 'Tỉnh/Thành phố';
    protected static ?string $modelLabel       = 'Tỉnh/Thành phố';
    protected static ?string $pluralModelLabel = 'Tỉnh/Thành phố';
    protected static ?int    $navigationSort   = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin tỉnh/thành phố')->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label('Tên tỉnh/thành phố')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),

                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                ]),

                FileUpload::make('image')
                    ->label('Hình ảnh')
                    ->image()
                    ->directory('provinces')
                    ->nullable(),
            ]),

            Section::make('Chi nhánh tại tỉnh/thành phố')->schema([
                TableRepeater::make('branches')
                    ->relationship('branches')
                    ->headers([
                        Header::make('categorie_id')->label('Danh mục'),
                        Header::make('status')->label('Hiển thị')->width('120px'),
                    ])
                    ->schema([
                        Select::make('categorie_id')
                            ->label('Danh mục')
                            ->options(
                                Category::where('category_type', 'product')
                                    ->where('status', true)
                                    ->whereNull('parent_id')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required(),

                        Toggle::make('status')
                            ->label('Hiển thị')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->addActionLabel('Thêm chi nhánh')
                    ->emptyLabel('Chưa có chi nhánh nào')
                    ->reorderable(false)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Ảnh')
                    ->square()
                    ->size(50),

                TextColumn::make('name')
                    ->label('Tên tỉnh/thành phố')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),

                TextColumn::make('branches_count')
                    ->label('Số chi nhánh')
                    ->counts('branches')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->actions([
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProvinces::route('/'),
            'create' => Pages\CreateProvince::route('/create'),
            'edit'   => Pages\EditProvince::route('/{record}/edit'),
        ];
    }
}
