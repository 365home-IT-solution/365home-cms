<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProvinceResource\Pages;
use App\Filament\Resources\ProvinceResource\RelationManagers\WardsRelationManager;
use App\Models\Province;
use App\Models\Ward;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
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
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationLabel  = 'Tỉnh/Thành phố';
    protected static ?string $modelLabel       = 'Tỉnh/Thành phố';
    protected static ?string $pluralModelLabel = 'Tỉnh/Thành phố';
    protected static ?int    $navigationSort   = 10;

    // Trước đây hardcode isSuperAdmin() — bỏ qua ProvincePolicy (đã đúng, kiểm tra
    // view_any_province), khiến tick/bỏ tick quyền này ở Roles & Permissions vô tác dụng.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_province') ?? false;
    }

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
                        ->label('Slug (URL param)')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->helperText('Dùng cho ?province=slug'),
                ]),

                Grid::make(4)->schema([
                    TextInput::make('code')
                        ->label('Mã tỉnh')
                        ->numeric()
                        ->unique(ignoreRecord: true)
                        ->placeholder('VD: 1')
                        ->helperText('Mã hành chính (provinces.open-api.vn)'),

                    TextInput::make('division_type')
                        ->label('Loại đơn vị')
                        ->maxLength(100)
                        ->placeholder('VD: tỉnh, thành phố trung ương'),

                    TextInput::make('codename')
                        ->label('Codename')
                        ->maxLength(100)
                        ->unique(ignoreRecord: true)
                        ->placeholder('VD: ha_noi')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $state
                            ? $set('slug', str_replace('_', '-', $state))
                            : null
                        )
                        ->helperText('Tự động cập nhật slug khi nhập'),

                    TextInput::make('phone_code')
                        ->label('Mã vùng')
                        ->numeric()
                        ->placeholder('VD: 24'),
                ]),

                FileUpload::make('image')
                    ->label('Hình ảnh')
                    ->image()
                    ->directory('provinces')
                    ->nullable(),

                Grid::make(2)->schema([
                    TextInput::make('lat')
                        ->label('Vĩ độ (Latitude)')
                        ->numeric()
                        ->placeholder('VD: 21.0285')
                        ->helperText('Dùng để xác định vị trí GPS gần nhất')
                        ->nullable(),

                    TextInput::make('lng')
                        ->label('Kinh độ (Longitude)')
                        ->numeric()
                        ->placeholder('VD: 105.8542')
                        ->nullable(),
                ]),
            ]),

            Section::make('Chi nhánh tại tỉnh/thành phố')->schema([
                TableRepeater::make('branches')
                    ->relationship('branches')
                    ->headers([
                        Header::make('ward_code')->label('Phường/Xã'),
                        Header::make('categorie_id')->label('Chi nhánh'),
                        Header::make('status')->label('Hiển thị')->width('120px'),
                    ])
                    ->schema([
                        Select::make('ward_code')
                            ->label('Phường/Xã')
                            ->options(fn (Get $get) => Ward::where('province_code', $get('../../code') ?? 0)
                                ->orderBy('name')
                                ->pluck('name', 'code')
                            )
                            ->searchable()
                            ->nullable()
                            ->placeholder('— Chưa chọn —'),

                        Select::make('categorie_id')
                            ->label('Chi nhánh')
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

                TextColumn::make('code')
                    ->label('Mã')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('name')
                    ->label('Tên tỉnh/thành phố')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('division_type')
                    ->label('Loại')
                    ->badge()
                    ->color(fn (?string $state): string => match (true) {
                        str_contains((string) $state, 'trung ương') => 'warning',
                        str_contains((string) $state, 'thành phố') => 'info',
                        default                                     => 'gray',
                    }),

                TextColumn::make('codename')
                    ->label('Codename')
                    ->searchable()
                    ->placeholder('—'),

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

    public static function getRelationManagers(): array
    {
        return [
            WardsRelationManager::class,
        ];
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
