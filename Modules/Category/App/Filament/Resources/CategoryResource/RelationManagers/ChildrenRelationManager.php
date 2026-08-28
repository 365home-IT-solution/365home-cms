<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\RelationManagers;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Khu vực/chi nhánh con (Category::children(), tự lọc theo parent_id = chi nhánh đang xem) — trước
// đây lẫn chung 1 danh sách phẳng với chi nhánh gốc ở CategoryTable (dùng CTE dựng cây), giờ
// CategoryTable chỉ còn hiện chi nhánh gốc nên phải có chỗ riêng để xem/quản lý con — đặt ở đây.
// Form KHÔNG có field "Thuộc chi nhánh"/"Đối tác sở hữu" như CategoryForm — parent_id do chính
// quan hệ HasMany tự gán (Eloquent ghi đè bất kể form chọn gì), partner_id ép theo đúng chi nhánh
// cha đang xem (children luôn cùng đối tác với cha, không cho chọn khác).
class ChildrenRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $title = 'Chi nhánh con';

    public function form(Form $form): Form
    {
        return $form->schema([
            Hidden::make('category_type')->default('product')->dehydrated(),

            TextInput::make('name')
                ->label('Tên địa điểm')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                    if (($get('slug') ?? '') !== Str::slug($old)) {
                        return;
                    }
                    $set('slug', Str::slug($state));
                }),

            TextInput::make('slug')
                ->label('Đường dẫn')
                ->required()
                ->rules([function (Get $get) {
                    $categoryId = $get('id');
                    return $categoryId
                        ? Rule::unique('categories', 'slug')->ignore($categoryId)
                        : Rule::unique('categories', 'slug');
                }]),

            TextInput::make('sort_order')
                ->label('Thứ tự hiển thị')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0),

            Toggle::make('status')
                ->label('Trạng thái')
                ->onIcon('heroicon-o-eye')
                ->offIcon('heroicon-o-eye-slash')
                ->default(true)
                ->required(),

            Textarea::make('description')
                ->label('Mô tả')
                ->rows(3),

            FileUpload::make('image')
                ->label('Hình ảnh địa điểm')
                ->image()
                ->imageEditor()
                ->directory('categories')
                ->imagePreviewHeight('100')
                ->nullable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('image')
                    ->label('Hình ảnh')
                    ->defaultImageUrl(Storage::url('no-image.jpg')),

                TextColumn::make('name')
                    ->label('Tên địa điểm')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->sortable()
                    ->alignCenter(),

                ToggleColumn::make('status')
                    ->label('Trạng thái')
                    ->onIcon('heroicon-o-eye')
                    ->offIcon('heroicon-o-eye-slash')
                    ->alignCenter(),
            ])
            ->headerActions([
                CreateAction::make()
                    // partner_id luôn theo đúng chi nhánh cha đang xem — children không được phép
                    // thuộc đối tác khác cha (đúng bất biến mà CategoryObserver::saved() đang cascade).
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['partner_id'] = $this->getOwnerRecord()->partner_id;

                        return $data;
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([]);
    }
}
