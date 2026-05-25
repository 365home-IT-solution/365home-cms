<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Pages;

use CodeWithDennis\FilamentSelectTree\SelectTree;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\Entities\Category;
use Modules\Product\App\Filament\Resources\ProductResource;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::createCategoryAction(),
            self::createAdditionServiceAction(),
        ];
    }

    private static function createCategoryAction(): Action
    {
        return Action::make('Tạo chi nhánh/khu vực')
            ->label('Tạo Chi nhánh và Khu vực')
            ->model(Category::class)
            ->modalHeading('Tạo Chi nhánh và Khu vực mới')
            ->modalSubmitActionLabel('Tạo')
            ->modalCancelActionLabel('Hủy')
            ->form([
                Grid::make(4)->schema([
                    Section::make()->schema([
                        TextInput::make('name')
                            ->label(__('category::category.form.label.name'))
                            ->placeholder(__('category::category.form.placeholder.name'))
                            ->required()
                            ->rules(['max:255'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                                if (($get('slug') ?? '') !== Str::slug($old)) {
                                    return;
                                }
                                $set('slug', Str::slug($state));
                            })
                            ->columnSpan(1),

                        TextInput::make('slug')
                            ->label(__('category::category.form.label.slug'))
                            ->placeholder(__('category::category.form.placeholder.slug'))
                            ->required()
                            ->rules([function (Get $get) {
                                $categoryId = $get('id');
                                return $categoryId
                                    ? Rule::unique('categories', 'slug')->ignore($categoryId)
                                    : Rule::unique('categories', 'slug');
                            }])
                            ->columnSpan(1),

                        Textarea::make('description')
                            ->label(__('category::category.form.label.description'))
                            ->placeholder(__('category::category.form.placeholder.description'))
                            ->rows(3)
                            ->columnSpan(2),
                    ])
                    ->columns(2)
                    ->columnSpan(3),

                    Section::make()->schema([
                        SelectTree::make('parent_id')
                            ->label(__('category::category.form.label.parent_id'))
                            ->placeholder(__('category::category.form.placeholder.parent_id'))
                            ->relationship('parent', 'name', 'parent_id', function ($query, $get) {
                                $currentId   = $get('id');
                                $categoryType = $get('category_type');
                                if ($currentId) {
                                    $query->where('id', '!=', $currentId)
                                        ->where(fn ($q) => $q->where('parent_id', '!=', $currentId)->orWhereNull('parent_id'));
                                }
                                if ($categoryType) {
                                    $query->where('category_type', $categoryType);
                                }
                                return $query;
                            })
                            ->live(onBlur: true)
                            ->enableBranchNode()
                            ->nullable()
                            ->dehydrateStateUsing(function ($state, $get) {
                                return $state == $get('id') ? null : $state;
                            }),

                        Select::make('category_type')
                            ->label(__('category::category.form.label.category_type'))
                            ->placeholder(__('category::category.form.placeholder.category_type'))
                            ->required()
                            ->live(onBlur: true)
                            ->options([
                                'product' => __('category::category.form.options.product'),
                                'post'    => __('category::category.form.options.post'),
                            ]),

                        Toggle::make('status')
                            ->label(__('category::category.form.label.status'))
                            ->onIcon(__('category::category.form.icons.active'))
                            ->offIcon(__('category::category.form.icons.inactive'))
                            ->default(true)
                            ->required(),
                    ])->columnSpan(1),
                ]),

                FileUpload::make('image')
                    ->label(__('category::category.form.label.image'))
                    ->image()
                    ->imageEditor()
                    ->directory('categories')
                    ->imagePreviewHeight('100')
                    ->nullable()
                    ->hint('Tải lên hình ảnh địa điểm'),
            ])
            ->action(function (array $data) {
                Category::create([
                    'name'          => $data['name'],
                    'slug'          => $data['slug'],
                    'description'   => $data['description'] ?? null,
                    'parent_id'     => $data['parent_id'] ?? null,
                    'category_type' => $data['category_type'],
                    'status'        => $data['status'] ?? true,
                    'image'         => $data['image'] ?? null,
                ]);
            })
            ->closeModalByClickingAway(true);
    }

    private static function createAdditionServiceAction(): Action
    {
        return Action::make('createAdditionService')
            ->label('Thêm dịch vụ bổ sung')
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->modalHeading('Tạo dịch vụ bổ sung mới')
            ->modalSubmitActionLabel('Tạo dịch vụ')
            ->modalCancelActionLabel('Hủy')
            ->form([
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
            ])
            ->action(function (array $data) {
                AdditionService::create([
                    'name'      => $data['name'],
                    'price'     => $data['price'],
                    'image'     => $data['image'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                ]);

                \Filament\Notifications\Notification::make()
                    ->title('Đã tạo dịch vụ "' . $data['name'] . '"')
                    ->body('Dịch vụ đã sẵn sàng để chọn trong form bên dưới.')
                    ->success()
                    ->send();
            })
            ->closeModalByClickingAway(true);
    }
}
