<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Forms;

use AmidEsfahani\FilamentTinyEditor\TinyEditor;
use CodeWithDennis\FilamentSelectTree\SelectTree;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use TomatoPHP\FilamentMediaManager\Form\MediaManagerInput;

class ProductForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(3)->schema([
                // ── Cột trái (2/3) ──────────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    self::categoriesCard(),
                    self::basicInfoCard(),
                    self::descriptionsCard(),
                ]),

                // ── Cột phải (1/3) ──────────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    self::videoCard(),
                    self::imagesCard(),
                ]),
            ]),
        ]);
    }

    // ── Phân loại: chi nhánh, tiện ích, dịch vụ bổ sung ────────────────────
    private static function categoriesCard(): Section
    {
        return Section::make()->schema([
            SelectTree::make('categories')
                ->label(__('product::product.form.label.categories'))
                ->relationship(
                    relationship: 'categories',
                    titleAttribute: 'name',
                    parentAttribute: 'parent_id',
                    modifyQueryUsing: function ($query) {
                        $query->where('category_type', 'product');

                        $user = auth()->user();
                        if ($user && ! $user->isSuperAdmin()) {
                            $allowedIds = $user->allowedCategoryIds();
                            if (empty($allowedIds)) {
                                $query->whereRaw('1 = 0');
                            } else {
                                $query->whereIn('id', $allowedIds);
                            }
                        }

                        return $query;
                    }
                )
                ->required()
                ->placeholder(__('product::product.form.placeholder.categories'))
                ->enableBranchNode()
                ->helperText(__('product::product.form.helper_text.categories'))
                ->inlineLabel(),

            Select::make('tags')
                ->label(__('product::product.form.label.tags'))
                ->relationship('tags', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->getTranslation('name', app()->getLocale()))
                ->searchable()
                ->preload()
                ->multiple()
                ->placeholder(__('product::product.form.placeholder.tags'))
                ->helperText(__('product::product.form.helper_text.tags'))
                ->inlineLabel(),

            Select::make('additionalServices')
                ->label('Dịch vụ bổ sung')
                ->relationship(
                    name: 'additionalServices',
                    titleAttribute: 'name',
                    modifyQueryUsing: function ($query) {
                        $query->where('is_active', true);

                        $user = auth()->user();
                        if (! $user || $user->isSuperAdmin()) {
                            return $query;
                        }

                        $allowedIds = $user->allowedCategoryIds();
                        if (empty($allowedIds)) {
                            return $query->whereRaw('1 = 0');
                        }

                        $productTable = (new Product())->getTable();
                        $roomIds = \Illuminate\Support\Facades\DB::table('categorizables')
                            ->where('categorizable_type', Product::class)
                            ->whereIn('category_id', $allowedIds)
                            ->pluck('categorizable_id');

                        // Dịch vụ trong chi nhánh được phép HOẶC chưa gán cho phòng nào
                        return $query->where(function ($q) use ($productTable, $roomIds) {
                            $q->whereHas('products', fn ($sq) => $sq->whereIn("{$productTable}.id", $roomIds))
                              ->orDoesntHave('products');
                        });
                    }
                )
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name . ' — ' . number_format($record->price) . '₫')
                ->searchable()
                ->preload()
                ->multiple()
                ->placeholder('Chọn dịch vụ bổ sung cho phòng...')
                ->helperText('Dịch vụ sẽ được gán vào phòng này')
                ->inlineLabel(),
        ])->columns(1);
    }

    // ── Thông tin cơ bản ────────────────────────────────────────────────────
    private static function basicInfoCard(): Section
    {
        return Section::make()->schema([
            TextInput::make('name')
                ->label(__('product::product.form.label.name'))
                ->helperText(__('product::product.form.helper_text.name'))
                ->required()
                ->live(onBlur: true)
                ->placeholder(__('product::product.form.placeholder.name'))
                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', str()->slug($state)))
                ->inlineLabel(),

            TextInput::make('slug')
                ->label(__('product::product.form.label.slug'))
                ->required()
                ->unique(ignoreRecord: true)
                ->placeholder(__('product::product.form.placeholder.slug'))
                ->inlineLabel(),

            TextInput::make('wifi')
                ->label('Pass Wifi')
                ->maxLength(100)
                ->placeholder('Nhập mật khẩu wifi')
                ->inlineLabel(),

            TextInput::make('home_code')
                ->label('Mã mở khóa')
                ->maxLength(255)
                ->placeholder('Nhập mã mở khóa')
                ->inlineLabel(),

            TextInput::make('address')
                ->label('Địa chỉ')
                ->maxLength(100)
                ->placeholder('Nhập địa chỉ chi nhánh')
                ->inlineLabel(),

            TextInput::make('map_url')
                ->label('Địa chỉ đường dẫn Google Map')
                ->maxLength(500)
                ->placeholder('https://maps.app.goo.gl/...')
                ->url()
                ->inlineLabel(),

            TextInput::make('hotline')
                ->label('Hotline chi nhánh')
                ->maxLength(255)
                ->inlineLabel(),

            ToggleButtons::make('styles')
                ->label('Kiểu đặt phòng')
                ->options([
                    1 => 'Theo khung giờ',
                    2 => 'Theo ngày',
                ])
                ->default(1)
                ->required()
                ->live()
                ->grouped()
                ->colors([1 => 'success', 2 => 'info'])
                ->icons([1 => 'heroicon-o-clock', 2 => 'heroicon-o-calendar-days'])
                ->inlineLabel(),
        ])->columns(1);
    }

    // ── Mô tả ───────────────────────────────────────────────────────────────
    private static function descriptionsCard(): Section
    {
        return Section::make()->schema([
            TinyEditor::make('short_description')
                ->label(__('product::product.form.label.short_description'))
                ->id('product_short_description')
                ->profile('custom-full')
                ->inlineLabel(),

            TinyEditor::make('description')
                ->label(__('product::product.form.label.description'))
                ->id('product_description')
                ->nullable()
                ->profile('custom-full')
                ->inlineLabel(),
        ])->columns(1);
    }

    // ── Video ───────────────────────────────────────────────────────────────
    private static function videoCard(): Section
    {
        return Section::make()
            ->schema([
                TextInput::make('setting_video_room.url')
                    ->label('URL Video')
                    ->placeholder('https://www.youtube.com/watch?v=...')
                    ->helperText('Hỗ trợ: YouTube · Shorts · TikTok · Facebook · .mp4')
                    ->maxLength(1000)
                    ->inlineLabel(),

                ToggleButtons::make('setting_video_room.ratio')
                    ->label('Tỉ lệ khung hình')
                    ->options([
                        '16:9' => '16:9 Ngang',
                        '9:16' => '9:16 Dọc',
                        '4:3'  => '4:3 Cổ điển',
                    ])
                    ->default('16:9')
                    ->grouped()
                    ->colors(['16:9' => 'info', '9:16' => 'success', '4:3' => 'warning'])
                    ->inlineLabel(),

                TextInput::make('setting_video_room.title')
                    ->label('Tiêu đề video')
                    ->placeholder('VD: Trải nghiệm phòng Deluxe - 365 Home')
                    ->maxLength(200)
                    ->inlineLabel(),
            ])
            ->columns(1);
    }

    // ── Hình ảnh ────────────────────────────────────────────────────────────
    private static function imagesCard(): Section
    {
        return Section::make()->schema([
            MediaManagerInput::make('Ảnh bìa')
                ->label(__('product::product.form.label.image_main'))
                ->required()
                ->schema([])
                ->defaultItems(1)
                ->minItems(1)
                ->maxItems(1)
                ->columnSpanFull()
                ->inlineLabel(),

            MediaManagerInput::make('Thư viện')
                ->label(__('product::product.form.label.gallery'))
                ->schema([])
                ->defaultItems(0)
                ->minItems(0)
                ->grid(2)
                ->maxItems(8)
                ->columnSpanFull(),
        ])->columns(1);
    }
}
