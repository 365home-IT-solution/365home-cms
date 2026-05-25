<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\Entities\Category;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\Product\App\Filament\Resources\AdditionServiceResource\Pages;
use Modules\Product\App\Models\Product;

class AdditionServiceResource extends Resource
{
    protected static ?string $model = AdditionService::class;

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return parent::getEloquentQuery();
        }

        // Lấy parent category IDs mà user được phân quyền
        $parentCategoryIds = UserBranchPermission::where('user_id', $user->id)
            ->pluck('category_id');

        if ($parentCategoryIds->isEmpty()) {
            return parent::getEloquentQuery()->whereRaw('0 = 1');
        }

        // Lấy thêm child category IDs của những parent đó
        $childCategoryIds = Category::whereIn('parent_id', $parentCategoryIds)->pluck('id');

        $allCategoryIds = $parentCategoryIds->merge($childCategoryIds)->unique();

        // Tìm room IDs thuộc những categories đó (qua bảng categorizables)
        $roomIds = DB::table('categorizables')
            ->where('categorizable_type', Product::class)
            ->whereIn('category_id', $allCategoryIds)
            ->pluck('categorizable_id');

        // Chỉ hiển thị dịch vụ bổ sung được gán cho những phòng đó
        $productTable = (new Product())->getTable();

        return parent::getEloquentQuery()
            ->whereHas('products', fn (Builder $q) => $q->whereIn("{$productTable}.id", $roomIds));
    }

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
