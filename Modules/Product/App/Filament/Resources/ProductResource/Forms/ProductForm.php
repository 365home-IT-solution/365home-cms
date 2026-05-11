<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Forms;

use AmidEsfahani\FilamentTinyEditor\TinyEditor;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use CodeWithDennis\FilamentSelectTree\SelectTree;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Get;
use Modules\Product\App\Models\TimeSlot;
use TomatoPHP\FilamentMediaManager\Form\MediaManagerInput;

class ProductForm
{
    private const MAX_NUMERIC_VALUE = 999999999999999.99;
    private const MAX_DIMENSION_VALUE = 9999999999.99;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Grid::make()
                ->columns()
                ->schema([
                    self::createMainContent(),
                ])
        ]);
    }

    private static function createMainContent(): Grid
    {
        return Grid::make()
            ->schema([
                self::createCategoriesAndTagsSection(),
                self::createBasicInformationSection(),
                self::createVideoSection(),
//                self::createPricingSection(),
//                self::createInventorySection(),
//                self::createDimensionsFields(),

            ]);
    }

    private static function createBasicInformationSection(): Section
    {
        return Section::make(__('product::product.form.sections.basic'))
            ->description(__('product::product.form.descriptions.basic'))
            ->schema([
                Card::make()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('product::product.form.label.name'))
                            ->helperText(__('product::product.form.helper_text.name'))
                            ->required()
                            ->live(onBlur: true)
                            ->placeholder(__('product::product.form.placeholder.name'))
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('slug', str()->slug($state));
                            })
                            ->inlineLabel(),
                        TextInput::make('slug')
                            ->label(__('product::product.form.label.slug'))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->placeholder(__('product::product.form.placeholder.slug'))
                            ->inlineLabel(),
                        TextInput::make('wifi')
                            ->label(__('Pass Wifi'))
                            ->maxLength(100)
                            ->placeholder(__('Nhập mật khẩu wifi'))
                            ->inlineLabel(),
                        TextInput::make('home_code')
                            ->label(__('Mã mở khóa'))
                            ->maxLength(255)
                            ->placeholder(__('Nhập mã mở khóa'))
                            ->inlineLabel(),
                        TextInput::make('address')
                            ->label(__('Địa chỉ'))
                            ->maxLength(100)
                            ->placeholder(__('Nhập địa chỉ chi nhánh'))
                            ->inlineLabel(),
                        TextInput::make('hotline')
                            ->label(__('Hotline chi nhánh'))
                            ->maxLength(255)
                            ->placeholder(__(''))
                            ->inlineLabel(),
                        ToggleButtons::make('styles')
                            ->label(__('Kiểu đặt phòng'))
                            ->options([
                                1 => 'Theo khung giờ',
                                2 => 'Theo ngày',
                            ])
                            ->default(1)
                            ->required()
                            ->live()
                            ->grouped()
                            ->colors([
                                1 => 'success',
                                2 => 'info',
                            ])
                            ->icons([
                                1 => 'heroicon-o-clock',
                                2 => 'heroicon-o-calendar-days',
                            ])
                            ->inlineLabel(),
                    ])
                    ->columns(1),

//                Card::make()
//                    ->schema([
//                        ToggleButtons::make('type')
//                            ->label(__('product::product.form.label.type'))
//                            ->options([
//                                'simple' => __('product::product.form.options.type.simple'),
//                                // 'variable' => __('product::product.form.options.type.variable'),
//                                // *** Khi nào có biến thể thì mở ra.
//                                'service' => __('product::product.form.options.type.service'),
//                            ])
//                            ->default('simple')
//                            ->required()
//                            ->live()
//                            ->grouped()
//                            ->colors([
//                                'simple' => 'success',
//                                'variable' => 'danger',
//                                'service' => 'warning',
//                            ])
//                            ->inlineLabel()
//                            ->columnSpanFull(),
//                        Toggle::make('is_activated')
//                            ->label(__('product::product.form.label.is_activated'))
//                            ->default(true)
//                            ->inline()
//                            ->inlineLabel()
//                            ->columnSpanFull(),
//                        Toggle::make('is_trend')
//                            ->label(__('product::product.form.label.is_trend'))
//                            ->default(false)
//                            ->inline()
//                            ->inlineLabel()
//                            ->columnSpanFull(),
//                    ])
//                    ->columns(),

                Card::make()
                    ->schema([
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
                            ->grid(3)
                            ->maxItems(8)
                            ->columnSpanFull()
                            ->inlineLabel(),
                    ])
                    ->columns(1),
                Card::make()
                    ->schema([
                        Grid::make(1)
                            ->schema([
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
                                    ->inlineLabel()
//
                            ])
                    ]),
            ])
            ->collapsible();
    }

    private static function createVideoSection(): Section
    {
        return Section::make('Video phòng')
            ->description('Nhúng video giới thiệu phòng (YouTube, YouTube Shorts, TikTok, Facebook Video, file .mp4...)')
            ->icon('heroicon-o-play-circle')
            ->schema([
                Card::make()
                    ->schema([
                        TextInput::make('setting_video_room.url')
                            ->label('URL Video')
                            ->placeholder('https://www.youtube.com/watch?v=... hoặc https://www.tiktok.com/@.../video/...')
                            ->helperText('Hỗ trợ: YouTube · YouTube Shorts · TikTok · Facebook Video · link .mp4 trực tiếp')
                            ->maxLength(1000)
                            ->inlineLabel(),
                        ToggleButtons::make('setting_video_room.ratio')
                            ->label('Tỉ lệ khung hình')
                            ->helperText('16:9 = ngang (YouTube/phim) · 9:16 = dọc (Shorts/TikTok/Reels) · 4:3 = cổ điển')
                            ->options([
                                '16:9' => '⬛ 16:9 Ngang',
                                '9:16' => '▬ 9:16 Dọc',
                                '4:3'  => '🔲 4:3 Cổ điển',
                            ])
                            ->default('16:9')
                            ->grouped()
                            ->colors([
                                '16:9' => 'info',
                                '9:16' => 'success',
                                '4:3'  => 'warning',
                            ])
                            ->inlineLabel(),
                        TextInput::make('setting_video_room.title')
                            ->label('Tiêu đề video (SEO)')
                            ->placeholder('VD: Trải nghiệm phòng Deluxe - 365 Home')
                            ->helperText('Xuất hiện trong thẻ <title>, og:title và schema VideoObject — ảnh hưởng thứ hạng SEO')
                            ->maxLength(200)
                            ->inlineLabel(),
                    ])
                    ->columns(1),
            ])
            ->collapsible()
            ->collapsed();
    }

    private static function createPricingSection(): Section
    {
        return Section::make(__('product::product.form.sections.pricing'))
            ->description(__('product::product.form.descriptions.pricing'))
            ->schema([
                Card::make()
                    ->schema([
                        TextInput::make('price')
                            ->label(__('product::product.form.label.price'))
                            ->numeric()
                            ->prefix('đ')
                            ->placeholder(__('product::product.form.placeholder.price'))
                            ->maxValue(self::MAX_NUMERIC_VALUE)
                            ->helperText(__('product::product.form.helper_text.price'))
                            ->inlineLabel(),
                        TextInput::make('discount')
                            ->label(__('product::product.form.label.discount'))
                            ->numeric()
                            ->prefix('đ')
                            ->placeholder(__('product::product.form.placeholder.discount'))
                            ->maxValue(self::MAX_NUMERIC_VALUE)
                            ->lte('price')
                            ->helperText(__('product::product.form.helper_text.discount'))
                            ->inlineLabel(),
                        TextInput::make('vat')
                            ->label(__('product::product.form.label.vat'))
                            ->numeric()
                            ->suffix('%')
                            ->placeholder(__('product::product.form.placeholder.vat'))
                            ->maxValue(100)
                            ->helperText(__('product::product.form.helper_text.vat'))
                            ->inlineLabel(),
                    ])
                    ->columns(1),
            ])
            ->collapsible()
            ->disabled(function (Get $get): bool {
                return !filled($get('name')) &&
                    !filled($get('slug')) &&
                    !filled($get('image_main')) &&
                    !filled($get('type'));
            })
            ->live()
            ->reactive()
            ->visible(fn(Get $get): bool => in_array($get('type'), ['simple', 'service']));
    }

    private static function createInventorySection(): Section
    {
        return Section::make(__('product::product.form.sections.inventory'))
            ->description(__('product::product.form.descriptions.inventory'))
            ->schema([
                Card::make()
                    ->schema([
                        TextInput::make('stock_quantity')
                            ->label(__('product::product.form.label.stock_quantity'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->placeholder(__('product::product.form.placeholder.stock_quantity'))
                            ->suffix(__('product::product.form.suffix.stock_quantity'))
                            ->visible(fn($get) => $get('is_in_stock') === true)
                            ->helperText(__('product::product.form.helper_text.stock_quantity'))
                            ->inlineLabel(),

                        Toggle::make('is_in_stock')
                            ->label(__('product::product.form.label.is_in_stock'))
                            ->helperText(__('product::product.form.helper_text.is_in_stock'))
                            ->default(true)
                            ->live()
                            ->inline()
                            ->inlineLabel(),

                        Toggle::make('is_shipped')
                            ->label(__('product::product.form.label.is_shipped'))
                            ->helperText(__('product::product.form.helper_text.is_shipped'))
                            ->default(true)
                            ->inline()
                            ->inlineLabel(),
                    ])
                    ->columns(1),
            ])
            ->collapsible()
            ->visible(fn(Get $get): bool => in_array($get('type'), ['simple']));
    }

    private static function createCategoriesAndTagsSection(): Section
    {
        return Section::make(__('product::product.form.sections.categories'))
            ->description(__('product::product.form.descriptions.categories'))
            ->schema([
                Card::make()
                    ->schema([
                        SelectTree::make('categories')
                            ->label(__('product::product.form.label.categories'))
                            ->relationship(
                                relationship: 'categories',
                                titleAttribute: 'name',
                                parentAttribute: 'parent_id',
                                modifyQueryUsing: fn($query) => $query->where('category_type', 'product')
                            )
                            ->required()
                            ->placeholder(__('product::product.form.placeholder.categories'))
                            ->enableBranchNode()
                            ->helperText(__('product::product.form.helper_text.categories'))
                            ->inlineLabel(),
                        Select::make('tags')
                            ->label(__('product::product.form.label.tags'))
                            ->relationship('tags', 'name')
                            ->getOptionLabelFromRecordUsing(function ($record) {
                                return $record->getTranslation('name', app()->getLocale()); // nếu dùng Spatie Translatable
                            })
                            ->searchable()
                            ->preload()
                            ->multiple()
                            ->placeholder(__('product::product.form.placeholder.tags'))
                            ->helperText(__('product::product.form.helper_text.tags'))
                            ->inlineLabel()
                    ])
                    ->columns(1),
            ])
            ->collapsible();
    }

    private static function createDimensionsFields(): Section
    {
        return Section::make(__('product::product.form.sections.dimensions'))
            ->description(__('product::product.form.descriptions.dimensions'))
            ->schema([
                Card::make()
                    ->schema([
                        TextInput::make('weight')
                            ->label(__('product::product.form.label.weight'))
                            ->numeric()
                            ->suffix(__('product::product.form.suffix.weight'))
                            ->placeholder(__('product::product.form.placeholder.weight'))
                            ->maxValue(self::MAX_DIMENSION_VALUE)
                            ->helperText(__('product::product.form.helper_text.weight'))
                            ->inlineLabel(),
                        // Select::make('shipping_type')
                        //     ->label(__('product::product.form.label.shipping_type'))
                        //     ->options([])
                        //     ->helperText(__('product::product.form.helper_text.shipping_type'))
                        //     ->inlineLabel()
                        //     ->columns(4),
                        // *** Khi nào có phương thức vận chuyển thì mở ra.

                        TextInput::make('length')
                            ->label(__('product::product.form.label.length'))
                            ->numeric()
                            ->suffix(__('product::product.form.suffix.length'))
                            ->placeholder(__('product::product.form.placeholder.length'))
                            ->maxValue(self::MAX_DIMENSION_VALUE)
                            ->helperText(__('product::product.form.helper_text.length'))
                            ->inlineLabel(),
                        TextInput::make('width')
                            ->label(__('product::product.form.label.width'))
                            ->numeric()
                            ->suffix(__('product::product.form.suffix.width'))
                            ->placeholder(__('product::product.form.placeholder.width'))
                            ->maxValue(self::MAX_DIMENSION_VALUE)
                            ->helperText(__('product::product.form.helper_text.width'))
                            ->inlineLabel(),
                        TextInput::make('height')
                            ->label(__('product::product.form.label.height'))
                            ->numeric()
                            ->suffix(__('product::product.form.suffix.height'))
                            ->placeholder(__('product::product.form.placeholder.height'))
                            ->maxValue(self::MAX_DIMENSION_VALUE)
                            ->helperText(__('product::product.form.helper_text.height'))
                            ->inlineLabel(),
                    ])
                    ->columns(1),
            ])
            ->collapsible()
            ->visible(fn(Get $get): bool => in_array($get('type'), ['simple']));
    }

}
