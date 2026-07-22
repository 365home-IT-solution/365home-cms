<?php

namespace App\Filament\Pages\Setting;

use App\Services\FileService;
use App\Settings\GeneralSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Support\Htmlable;
use JulioMotol\FilamentPasswordConfirmation\RequiresPasswordConfirmation;
use Modules\Product\App\Models\Product;
use Riodwanto\FilamentAceEditor\AceEditor;

use function Filament\Support\is_app_url;

class ManageGeneral extends SettingsPage
{
    use HasPageShield,
        RequiresPasswordConfirmation;

    protected static string $settings = GeneralSettings::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public string $themePath = '';

    public string $twConfigPath = '';

    public function mount(): void
    {
        $this->themePath = resource_path('css/filament/admin/theme.css');
        $this->twConfigPath = resource_path('css/filament/admin/tailwind.config.js');

        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $settings = app(static::getSettings());

        $data = $this->mutateFormDataBeforeFill($settings->toArray());

        $fileService = new FileService;

        $data['theme-editor'] = $fileService->readfile($this->themePath);

        $data['tw-config-editor'] = $fileService->readfile($this->twConfigPath);

        $this->form->fill($data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Cài đặt trang web')
                    ->description(fn() => __('page.general_settings.sections.site.description'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->schema([
                        Forms\Components\Card::make()
                            ->schema([
                                Forms\Components\Grid::make()
                                    ->schema([
                                        Forms\Components\TextInput::make('brand_name')
                                            ->label(fn() => __('page.general_settings.fields.brand_name'))
                                            ->placeholder('Nhập tên thương hiệu')
                                            ->required()
                                            ->maxLength(255)
                                            ->columnSpan(2),
                                        Forms\Components\Toggle::make('site_active')
                                            ->label(fn() => __('page.general_settings.fields.site_active'))
                                            ->onIcon('heroicon-o-eye')
                                            ->offIcon('heroicon-o-eye-slash')
                                            ->inline(true)
                                            ->required()
                                            ->helperText('Bật/tắt hiển thị website')
                                            ->columnSpan(1),
                                    ])
                                    ->columns(3)
                            ])
                            ->columnSpan('full'),
                        Forms\Components\Card::make()
                            ->schema([
                                Forms\Components\Grid::make()
                                    ->schema([
                                        Forms\Components\Section::make('Logo thương hiệu')
                                            ->description('Upload và cấu hình logo chính của website')
                                            ->compact()
                                            ->schema([
                                                Forms\Components\FileUpload::make('brand_logo')
                                                    ->label(fn() => __('page.general_settings.fields.brand_logo'))
                                                    ->image()
                                                    ->imageEditor()
                                                    ->directory('sites')
                                                    ->visibility('public')
                                                    ->imagePreviewHeight('100')
                                                    ->moveFiles()
                                                    ->required()
                                                    ->columnSpan('full'),
                                                Forms\Components\FileUpload::make('brand_logo_light_version')
                                                    ->label('Logo Thương hiệu chế độ tối')
                                                    ->image()
                                                    ->imageEditor()
                                                    ->directory('sites')
                                                    ->visibility('public')
                                                    ->imagePreviewHeight('100')
                                                    ->moveFiles()
                                                    ->required()
                                                    ->columnSpan('full'),
                                                Forms\Components\TextInput::make('brand_logoHeight')
                                                    ->label(fn() => __('page.general_settings.fields.brand_logoHeight'))
                                                    ->required()
                                                    ->numeric()
                                                    ->suffix('px')
                                                    ->maxValue(200)
                                                    ->minValue(20)
                                                    ->default(40)
                                                    ->helperText('Chiều cao logo (20px - 200px)')
                                                    ->columnSpan('full'),
                                            ])
                                            ->columnSpan(2),
                                        Forms\Components\Section::make('Favicon')
                                            ->description('Upload favicon cho website')
                                            ->compact()
                                            ->schema([
                                                Forms\Components\FileUpload::make('site_favicon')
                                                    ->label(fn() => __('page.general_settings.fields.site_favicon'))
                                                    ->image()
                                                    ->imageEditor()
                                                    ->directory('sites')
                                                    ->visibility('public')
                                                    ->imagePreviewHeight('100')
                                                    ->moveFiles()
                                                    ->acceptedFileTypes(['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/webp'])
                                                    ->required()
                                                    ->columnSpan('full'),
                                            ])
                                            ->columnSpan(1),
                                    ])
                                    ->columns(3)
                            ])
                            ->columnSpan('full'),
                    ])
                    ->columns(1),
                Forms\Components\Tabs::make('Tabs')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Bảng màu')
                            ->schema([
                                Forms\Components\ColorPicker::make('site_theme.primary')
                                    ->label(fn() => __('page.general_settings.fields.primary')),
                                Forms\Components\ColorPicker::make('site_theme.Secondary')
                                    ->label('Màu màu chữ chính'),
                                Forms\Components\ColorPicker::make('site_theme.background')
                                    ->label('Màu nền')
                                    ->default('#222831'),

                                Forms\Components\ColorPicker::make('site_theme.bg_dark')
                                    ->label('Màu nền tối')
                                    ->default('#1a1e25'),

                                Forms\Components\ColorPicker::make('site_theme.text_dark')
                                    ->label('Màu chữ')
                                    ->default('#212529'),

                                Forms\Components\ColorPicker::make('site_theme.red_9c')
                                    ->label('Red9C')
                                    ->default('#9c273d'),

                                Forms\Components\ColorPicker::make('site_theme.border_gray')
                                    ->label('Màu viền')
                                    ->default('#343a40'),

                                Forms\Components\ColorPicker::make('site_theme.tick_green')
                                    ->label('Tick Green')
                                    ->default('#56f956'),

                                Forms\Components\ColorPicker::make('site_theme.tick_yellow')
                                    ->label('Tick Yellow')
                                    ->default('#ffff00'),

                                Forms\Components\ColorPicker::make('site_theme.tick_gray')
                                    ->label('Tick Gray')
                                    ->default('#808080'),
                                Forms\Components\ColorPicker::make('site_theme.secondary')
                                    ->label(fn() => __('page.general_settings.fields.secondary')),
                                Forms\Components\ColorPicker::make('site_theme.gray')
                                    ->label(fn() => __('page.general_settings.fields.gray')),
                                Forms\Components\ColorPicker::make('site_theme.success')
                                    ->label(fn() => __('page.general_settings.fields.success')),
                                Forms\Components\ColorPicker::make('site_theme.danger')
                                    ->label(fn() => __('page.general_settings.fields.danger')),
                                Forms\Components\ColorPicker::make('site_theme.info')
                                    ->label(fn() => __('page.general_settings.fields.info')),
                                Forms\Components\ColorPicker::make('site_theme.warning')
                                    ->label(fn() => __('page.general_settings.fields.warning')),
                            ])
                            ->columns(3),

                        Forms\Components\Tabs\Tab::make('Màu sản phẩm')
                            ->schema([
                                Forms\Components\Placeholder::make('product_count')
                                    ->label('Số lượng sản phẩm')
                                    ->content(function () {
                                        $count = Product::count();
                                        return "Tổng số sản phẩm: {$count}";
                                    }),

                                Forms\Components\Repeater::make('color_product')
                                    ->label('Màu sắc sản phẩm')
                                    ->schema([
                                        Forms\Components\Select::make('product_id')
                                            ->label('Chọn sản phẩm')
                                            ->options(function ($get) {
                                                $selectedIds = collect($get('../../color_product'))
                                                    ->pluck('product_id')
                                                    ->filter()
                                                    ->toArray();

                                                $currentId = $get('product_id');

                                                return Product::query()
                                                    ->when($selectedIds, function ($query) use ($selectedIds, $currentId) {
                                                        return $query->whereNotIn('id', array_diff($selectedIds, [$currentId]));
                                                    })
                                                    ->pluck('name', 'id');
                                            })
                                            ->required()
                                            ->searchable()
                                            ->live()
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                // Reload options
                                            }),

                                        Forms\Components\ColorPicker::make('color')
                                            ->label('Màu sắc')
                                            ->required(),

                                        Forms\Components\ColorPicker::make('color_text')
                                            ->label('Màu sắc chữ'),

                                        Forms\Components\TextInput::make('note')
                                            ->label('Ghi chú')
                                            ->placeholder('Ví dụ: Màu chủ đạo, màu nền...')
                                    ])
                                    ->columns(3)
                                    ->collapsible()
                                    ->defaultItems(0)
                                    ->addActionLabel('Thêm màu cho sản phẩm')
                                    ->reorderable()
                                    ->itemLabel(fn (array $state): ?string =>
                                        Product::find($state['product_id'])?->name ?? 'Sản phẩm chưa chọn'
                                    )
                                    ->live()
                            ]),

                        Forms\Components\Tabs\Tab::make('Biên tập mã')
                            ->schema([
                                Forms\Components\Grid::make()->schema([
                                    AceEditor::make('theme-editor')
                                        ->label('theme.css')
                                        ->mode('css')
                                        ->height('24rem'),
                                    AceEditor::make('tw-config-editor')
                                        ->label('tailwind.config.js')
                                        ->height('24rem')
                                ])
                            ]),

                        Forms\Components\Tabs\Tab::make('SEO & Metadata')
                            ->schema([
                                Forms\Components\Section::make('Basic SEO')
                                    ->schema([
                                        Forms\Components\TextInput::make('title')
                                            ->label('Title')
                                            ->columnSpan(2),
                                        Forms\Components\Textarea::make('description')
                                            ->label('Description')
                                            ->columnSpan(2),
                                        Forms\Components\TagsInput::make('keywords')
                                            ->label('Keywords')
                                            ->separator(',')
                                            ->columnSpan(2),
                                        Forms\Components\TextInput::make('canonical')
                                            ->label('Canonical URL')
                                            ->columnSpan(1),
                                        Forms\Components\Select::make('robots')
                                            ->label('Robots')
                                            ->options([
                                                'index, follow' => 'Index, Follow',
                                                'noindex, follow' => 'No Index, Follow',
                                                'index, nofollow' => 'Index, No Follow',
                                                'noindex, nofollow' => 'No Index, No Follow',
                                            ])
                                            ->columnSpan(1),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Open Graph')
                                    ->schema([
                                        Forms\Components\Select::make('og_type')
                                            ->label('Type')
                                            ->options([
                                                'website' => 'Website',
                                                'article' => 'Article',
                                                'profile' => 'Profile',
                                                'book' => 'Book',
                                                'music' => 'Music',
                                                'video' => 'Video',
                                            ])
                                            ->default('website'),
                                        Forms\Components\TextInput::make('og_url')
                                            ->label('URL'),
                                        Forms\Components\TextInput::make('og_title')
                                            ->label('Title'),
                                        Forms\Components\Textarea::make('og_description')
                                            ->label('Description'),
                                        Forms\Components\FileUpload::make('og_image')
                                            ->label('Image')
                                            ->image()
                                            ->directory('seo')
                                            ->visibility('public'),
                                        Forms\Components\Select::make('og_locale')
                                            ->label('Locale')
                                            ->options([
                                                'vi_VN' => 'Tiếng Việt',
                                                'en_US' => 'English (US)',
                                                'en_GB' => 'English (UK)'
                                            ])
                                            ->default('vi_VN'),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Twitter Card')
                                    ->schema([
                                        Forms\Components\Select::make('twitter_card')
                                            ->label('Card Type')
                                            ->options([
                                                'summary' => 'Summary',
                                                'summary_large_image' => 'Summary Large Image',
                                                'app' => 'App',
                                                'player' => 'Player',
                                            ])
                                            ->default('summary'),
                                        Forms\Components\TextInput::make('twitter_url')
                                            ->label('URL'),
                                        Forms\Components\TextInput::make('twitter_title')
                                            ->label('Title'),
                                        Forms\Components\Textarea::make('twitter_description')
                                            ->label('Description'),
                                        Forms\Components\FileUpload::make('twitter_image')
                                            ->label('Image')
                                            ->image()
                                            ->directory('seo'),
                                        Forms\Components\TextInput::make('twitter_site')
                                            ->label('Site (@username)'),
                                        Forms\Components\TextInput::make('twitter_creator')
                                            ->label('Creator (@username)'),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Additional Metadata')
                                    ->schema([
                                        Forms\Components\TextInput::make('author')
                                            ->label('Author'),
                                        Forms\Components\DateTimePicker::make('article_published_time')
                                            ->label('Article Published Time'),
                                        Forms\Components\DateTimePicker::make('article_modified_time')
                                            ->label('Article Modified Time'),
                                    ])
                                    ->columns(2),
                            ]),

                        Forms\Components\Tabs\Tab::make('Scripts & Code')
                            ->schema([
                                Forms\Components\Section::make('Header Scripts')
                                    ->description('Thêm các script vào phần HEAD của trang')
                                    ->schema([
                                        AceEditor::make('header_scripts')
                                            ->label('Header Scripts')
                                            ->helperText('Các script sẽ được chèn vào cuối thẻ HEAD')
                                            ->rows(4)
                                            ->columnSpan('full'),
                                    ]),

                                Forms\Components\Section::make('Body Scripts')
                                    ->description('Thêm các script vào phần BODY của trang')
                                    ->schema([
                                        AceEditor::make('body_scripts_top')
                                            ->label('Body Scripts (Top)')
                                            ->helperText('Các script sẽ được chèn ngay sau thẻ mở BODY')
                                            ->rows(4)
                                            ->columnSpan('full'),

                                        AceEditor::make('body_scripts_bottom')
                                            ->label('Body Scripts (Bottom)')
                                            ->helperText('Các script sẽ được chèn trước thẻ đóng BODY')
                                            ->rows(4)
                                            ->columnSpan('full'),
                                    ]),

                                Forms\Components\Section::make('Footer Scripts')
                                    ->description('Thêm các script vào phần FOOTER của trang')
                                    ->schema([
                                        AceEditor::make('footer_scripts')
                                            ->label('Footer Scripts')
                                            ->helperText('Các script sẽ được chèn vào cuối trang')
                                            ->rows(4)
                                            ->columnSpan('full'),
                                    ]),

                                Forms\Components\Section::make('Google Analytics')
                                    ->description('Cấu hình Google Analytics và Tag Manager')
                                    ->schema([
                                        Forms\Components\Grid::make()
                                            ->schema([
                                                Forms\Components\TextInput::make('google_analytics_id')
                                                    ->label('Google Analytics ID')
                                                    ->placeholder('UA-XXXXXXXXX-X or G-XXXXXXXXXX')
                                                    ->helperText('Nhập ID Google Analytics của bạn'),

                                                Forms\Components\Toggle::make('google_analytics_enabled')
                                                    ->label('Kích hoạt Google Analytics')
                                                    ->inline(),
                                            ]),

                                        Forms\Components\Grid::make()
                                            ->schema([
                                                Forms\Components\TextInput::make('google_tag_manager_id')
                                                    ->label('Google Tag Manager ID')
                                                    ->placeholder('GTM-XXXXXXX')
                                                    ->helperText('Nhập ID Google Tag Manager của bạn'),

                                                Forms\Components\Toggle::make('google_tag_manager_enabled')
                                                    ->label('Kích hoạt Google Tag Manager')
                                                    ->inline(),
                                            ]),

                                        Forms\Components\TextInput::make('google_site_verification')
                                            ->label('Google Site Verification')
                                            ->helperText('Mã xác minh Google Search Console')
                                            ->columnSpan('full'),
                                    ])
                            ]),

                        // TAB POPUP - THIẾT KẾ CHUYÊN NGHIỆP
                        Forms\Components\Tabs\Tab::make('POPUP')
                            ->icon('heroicon-o-rectangle-stack')
                            ->schema([
                                Forms\Components\Section::make()
                                    ->schema([
                                        // Toggle bật/tắt popup
                                        Forms\Components\Toggle::make('popup.enabled')
                                            ->label('Kích hoạt Popup')
                                            ->helperText('Bật/tắt hiển thị popup trên website')
                                            ->default(false)
                                            ->reactive()
                                            ->columnSpanFull(),

                                        Forms\Components\Placeholder::make('popup_guide')
                                            ->label('')
                                            ->content(new \Illuminate\Support\HtmlString('
                                                <div class="rounded-lg bg-primary-50 dark:bg-primary-900/20 p-4 border border-primary-200 dark:border-primary-800">
                                                    <h4 class="font-semibold text-primary-900 dark:text-primary-100 mb-2">📋 Hướng dẫn cấu hình Popup</h4>
                                                    <ul class="text-sm text-primary-700 dark:text-primary-300 space-y-1 list-disc list-inside">
                                                        <li>Tùy chỉnh kích thước, màu sắc và nội dung popup</li>
                                                        <li>Thêm hình ảnh, tiêu đề và nội dung chi tiết</li>
                                                        <li>Cấu hình nút hành động với icon từ Lucide</li>
                                                        <li>Thiết lập thời điểm hiển thị popup</li>
                                                    </ul>
                                                </div>
                                            '))
                                            ->hidden(fn (callable $get) => !$get('popup.enabled'))
                                            ->columnSpanFull(),

                                        // Wizard layout chuyên nghiệp hơn
                                        Forms\Components\Wizard::make([
                                            // Step 1: Thiết kế cơ bản
                                            Forms\Components\Wizard\Step::make('Thiết kế')
                                                ->icon('heroicon-o-paint-brush')
                                                ->schema([
                                                    Forms\Components\Grid::make(3)
                                                        ->schema([
                                                            Forms\Components\Select::make('popup.size')
                                                                ->label('📐 Kích thước Popup')
                                                                ->options([
                                                                    'sm' => 'Nhỏ (400px)',
                                                                    'md' => 'Trung bình (600px)',
                                                                    'lg' => 'Lớn (800px)',
                                                                    'xl' => 'Rất lớn (1000px)',
                                                                    'custom' => 'Tùy chỉnh',
                                                                ])
                                                                ->default('md')
                                                                ->reactive()
                                                                ->required(),

                                                          Forms\Components\Select::make('popup.size_unit')
                                                            ->label('Đơn vị')
                                                            ->options([
                                                                'px' => 'Pixel (px)',
                                                                '%'  => 'Phần trăm (%)',
                                                                'vw' => 'Viewport Width (vw)',
                                                                'vh' => 'Viewport Height (vh)',
                                                            ])
                                                            ->default('px')
                                                            ->reactive()
                                                            ->visible(fn (callable $get) => $get('popup.size') === 'custom'),

                                                        Forms\Components\TextInput::make('popup.custom_width')
                                                            ->label('Chiều rộng')
                                                            ->numeric()
                                                            ->suffix(fn (callable $get) => $get('popup.size_unit') ?? 'px')
                                                            ->minValue(1)
                                                            ->maxValue(fn (callable $get) => in_array($get('popup.size_unit'), ['%','vw','vh']) ? 100 : 1400)
                                                            ->helperText(fn (callable $get) => match($get('popup.size_unit')) {
                                                                '%'  => 'Phần trăm so với màn hình (1-100%)',
                                                                'vw' => 'Viewport width (1-100vw)',
                                                                'vh' => 'Viewport height (1-100vh)',
                                                                default => 'Pixel (300-1400px)',
                                                            })
                                                            ->visible(fn (callable $get) => $get('popup.size') === 'custom'),

                                                        Forms\Components\TextInput::make('popup.custom_height')
                                                            ->label('Chiều cao')
                                                            ->numeric()
                                                            ->suffix(fn (callable $get) => $get('popup.size_unit') ?? 'px')
                                                            ->minValue(1)
                                                            ->maxValue(fn (callable $get) => in_array($get('popup.size_unit'), ['%','vw','vh']) ? 100 : 900)
                                                            ->helperText(fn (callable $get) => match($get('popup.size_unit')) {
                                                                '%'  => 'Phần trăm so với màn hình (1-100%)',
                                                                'vh' => 'Viewport height (1-100vh)',
                                                                default => 'Pixel (200-900px)',
                                                            })
                                                            ->visible(fn (callable $get) => $get('popup.size') === 'custom'),
                                                        ]),

                                                    Forms\Components\Section::make('🎨 Nền Popup')
                                                        ->schema([
                                                            Forms\Components\Grid::make(3)
                                                                ->schema([
                                                                    Forms\Components\Select::make('popup.background_type')
                                                                        ->label('Loại nền')
                                                                        ->options([
                                                                            'color' => '🎨 Màu đơn',
                                                                            'gradient' => '🌈 Gradient',
                                                                            'image' => '🖼️ Hình ảnh',
                                                                        ])
                                                                        ->default('color')
                                                                        ->reactive()
                                                                        ->required(),

                                                                    Forms\Components\ColorPicker::make('popup.background_color')
                                                                        ->label('Màu nền')
                                                                        ->default('#ffffff')
                                                                        ->visible(fn (callable $get) => $get('popup.background_type') === 'color'),

                                                                    Forms\Components\TextInput::make('popup.border_radius')
                                                                        ->label('Bo góc')
                                                                        ->numeric()
                                                                        ->suffix('px')
                                                                        ->default(16)
                                                                        ->minValue(0)
                                                                        ->maxValue(50),
                                                                ]),

                                                            // Gradient settings
                                                            Forms\Components\Grid::make(3)
                                                                ->schema([
                                                                    Forms\Components\ColorPicker::make('popup.gradient_start')
                                                                        ->label('Màu bắt đầu')
                                                                        ->default('#6366f1'),

                                                                    Forms\Components\ColorPicker::make('popup.gradient_end')
                                                                        ->label('Màu kết thúc')
                                                                        ->default('#8b5cf6'),

                                                                    Forms\Components\Select::make('popup.gradient_direction')
                                                                        ->label('Hướng gradient')
                                                                        ->options([
                                                                            'to-r' => '→ Ngang',
                                                                            'to-b' => '↓ Dọc',
                                                                            'to-br' => '↘ Chéo phải',
                                                                            'to-bl' => '↙ Chéo trái',
                                                                        ])
                                                                        ->default('to-br'),
                                                                ])
                                                                ->visible(fn (callable $get) => $get('popup.background_type') === 'gradient'),

                                                            // Image settings
                                                            Forms\Components\Grid::make(3)
                                                                ->schema([
                                                                    Forms\Components\FileUpload::make('popup.background_image')
                                                                        ->label('Hình ảnh nền')
                                                                        ->image()
                                                                        ->imageEditor()
                                                                        ->directory('popup-backgrounds')
                                                                        ->maxSize(5120)
                                                                        ->columnSpan(3),

                                                                    Forms\Components\Select::make('popup.image_position')
                                                                        ->label('Vị trí')
                                                                        ->options([
                                                                            'center' => 'Giữa',
                                                                            'top' => 'Trên',
                                                                            'bottom' => 'Dưới',
                                                                        ])
                                                                        ->default('center'),

                                                                    Forms\Components\Select::make('popup.image_size')
                                                                        ->label('Kích thước')
                                                                        ->options([
                                                                            'cover' => 'Phủ kín',
                                                                            'contain' => 'Vừa vặn',
                                                                            'auto' => 'Tự động',
                                                                        ])
                                                                        ->default('cover'),

                                                                    Forms\Components\TextInput::make('popup.image_opacity')
                                                                        ->label('Độ mờ ảnh nền')
                                                                        ->numeric()
                                                                        ->suffix('%')
                                                                        ->default(100)
                                                                        ->minValue(10)
                                                                        ->maxValue(100),
                                                                ])
                                                                ->visible(fn (callable $get) => $get('popup.background_type') === 'image'),
                                                        ])
                                                        ->collapsible(),
                                                ]),

                                            // Step 2: Nội dung
                                            Forms\Components\Wizard\Step::make('Nội dung')
                                                ->icon('heroicon-o-document-text')
                                                ->schema([
                                                    Forms\Components\Section::make('📝 Nội dung chính')
                                                        ->schema([
                                                            Forms\Components\Grid::make(2)
                                                                ->schema([
                                                                    Forms\Components\FileUpload::make('popup.content_image')
                                                                        ->label('🖼️ Hình ảnh nội dung')
                                                                        ->image()
                                                                        ->imageEditor()
                                                                        ->directory('popup-content')
                                                                        ->maxSize(5120)
                                                                        ->imagePreviewHeight('200')
                                                                        ->helperText('Hình ảnh hiển thị trung tâm popup')
                                                                        ->columnSpan(2),

                                                                    Forms\Components\TextInput::make('popup.title')
                                                                        ->label('📌 Tiêu đề')
                                                                        ->maxLength(255)
                                                                        ->placeholder('Ví dụ: Ưu đãi đặc biệt dành cho bạn!')
                                                                        ->columnSpan(2),

                                                                    Forms\Components\Grid::make(3)
                                                                        ->schema([
                                                                            Forms\Components\Select::make('popup.title_size')
                                                                                ->label('Cỡ chữ')
                                                                                ->options([
                                                                                    'text-lg' => 'Nhỏ',
                                                                                    'text-xl' => 'Vừa',
                                                                                    'text-2xl' => 'Lớn',
                                                                                    'text-3xl' => 'Rất lớn',
                                                                                    'text-4xl' => 'Khổng lồ',
                                                                                ])
                                                                                ->default('text-2xl'),

                                                                            Forms\Components\ColorPicker::make('popup.title_color')
                                                                                ->label('Màu tiêu đề')
                                                                                ->default('#1f2937'),

                                                                            Forms\Components\Select::make('popup.title_weight')
                                                                                ->label('Độ đậm')
                                                                                ->options([
                                                                                    'font-normal' => 'Bình thường',
                                                                                    'font-medium' => 'Trung bình',
                                                                                    'font-semibold' => 'Đậm',
                                                                                    'font-bold' => 'Rất đậm',
                                                                                ])
                                                                                ->default('font-bold'),
                                                                        ])
                                                                        ->columnSpan(2),

                                                                    Forms\Components\RichEditor::make('popup.content')
                                                                        ->label('✍️ Nội dung chi tiết')
                                                                        ->placeholder('Nhập nội dung mô tả cho popup...')
                                                                        ->toolbarButtons([
                                                                            'bold',
                                                                            'italic',
                                                                            'underline',
                                                                            'strike',
                                                                            'link',
                                                                            'bulletList',
                                                                            'orderedList',
                                                                        ])
                                                                        ->columnSpan(2),

                                                                    Forms\Components\ColorPicker::make('popup.content_color')
                                                                        ->label('Màu nội dung')
                                                                        ->default('#6b7280'),
                                                                ]),
                                                        ]),
                                                ]),

                                            // Step 3: Nút hành động (CTA)
                                            Forms\Components\Wizard\Step::make('Nút CTA')
                                                ->icon('heroicon-o-cursor-arrow-rays')
                                                ->schema([
                                                    Forms\Components\Placeholder::make('icon_guide')
                                                        ->label('')
                                                        ->content(new \Illuminate\Support\HtmlString('
                                                            <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 p-4 border border-blue-200 dark:border-blue-800">
                                                                <h4 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">💡 Hướng dẫn sử dụng Icon Lucide</h4>
                                                                <p class="text-sm text-blue-700 dark:text-blue-300 mb-2">
                                                                    Sử dụng tên icon từ thư viện <strong>Lucide Icons</strong> (không cần prefix).
                                                                </p>
                                                                <div class="bg-white dark:bg-gray-800 rounded p-3 font-mono text-sm">
                                                                    <p class="text-gray-600 dark:text-gray-400 mb-2">Ví dụ các icon phổ biến:</p>
                                                                    <ul class="space-y-1 text-gray-700 dark:text-gray-300">
                                                                        <li>• <strong>check</strong> - Dấu tick ✓</li>
                                                                        <li>• <strong>arrow-right</strong> - Mũi tên phải →</li>
                                                                        <li>• <strong>shopping-cart</strong> - Giỏ hàng 🛒</li>
                                                                        <li>• <strong>heart</strong> - Trái tim ❤️</li>
                                                                        <li>• <strong>star</strong> - Ngôi sao ⭐</li>
                                                                        <li>• <strong>x</strong> - Đóng ✕</li>
                                                                        <li>• <strong>gift</strong> - Quà tặng 🎁</li>
                                                                        <li>• <strong>sparkles</strong> - Hiệu ứng ✨</li>
                                                                    </ul>
                                                                </div>
                                                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-3">
                                                                    📚 Xem thêm: <a href="https://lucide.dev/icons/" target="_blank" class="underline hover:text-blue-800">lucide.dev/icons</a>
                                                                </p>
                                                            </div>
                                                        '))
                                                        ->columnSpanFull(),

                                                    Forms\Components\Repeater::make('popup.buttons')
                                                        ->label('Danh sách nút hành động')
                                                        ->schema([
                                                            Forms\Components\Section::make()
                                                                ->schema([
                                                                    Forms\Components\Grid::make(12)
                                                                        ->schema([
                                                                            Forms\Components\TextInput::make('text')
                                                                                ->label('📝 Nội dung nút')
                                                                                ->placeholder('VD: Mua ngay, Xem chi tiết, Đóng...')
                                                                                ->required()
                                                                                ->columnSpan(6),

                                                                            Forms\Components\Textarea::make('icon_svg')
                                                                                ->label('🎨 Icon SVG')
                                                                                ->placeholder('Dán code SVG vào đây...')
                                                                                ->helperText('Dán toàn bộ code SVG từ Lucide Icons')
                                                                                ->rows(3)
                                                                                ->columnSpan(6),

                                                                            Forms\Components\TextInput::make('url')
                                                                                ->label('🔗 Đường dẫn URL')
                                                                                ->placeholder('https://example.com')
                                                                                ->url()
                                                                                ->columnSpan(8),

                                                                            Forms\Components\Select::make('target')
                                                                                ->label('🎯 Mở link')
                                                                                ->options([
                                                                                    '_self' => 'Cùng tab',
                                                                                    '_blank' => 'Tab mới',
                                                                                ])
                                                                                ->default('_self')
                                                                                ->columnSpan(4),

                                                                            Forms\Components\Select::make('style')
                                                                                ->label('🎨 Kiểu nút')
                                                                                ->options([
                                                                                    'solid' => 'Đặc (Solid)',
                                                                                    'outline' => 'Viền (Outline)',
                                                                                    'ghost' => 'Trong suốt (Ghost)',
                                                                                ])
                                                                                ->default('solid')
                                                                                ->columnSpan(4),

                                                                            Forms\Components\Select::make('size')
                                                                                ->label('📏 Kích thước')
                                                                                ->options([
                                                                                    'sm' => 'Nhỏ',
                                                                                    'md' => 'Trung bình',
                                                                                    'lg' => 'Lớn',
                                                                                ])
                                                                                ->default('md')
                                                                                ->columnSpan(4),

                                                                            Forms\Components\Select::make('icon_position')
                                                                                ->label('📍 Vị trí icon')
                                                                                ->options([
                                                                                    'left' => 'Bên trái',
                                                                                    'right' => 'Bên phải',
                                                                                ])
                                                                                ->default('left')
                                                                                ->columnSpan(4),

                                                                            Forms\Components\ColorPicker::make('background_color')
                                                                                ->label('🎨 Màu nền nút')
                                                                                ->default('#3b82f6')
                                                                                ->columnSpan(6),

                                                                            Forms\Components\ColorPicker::make('text_color')
                                                                                ->label('✏️ Màu chữ')
                                                                                ->default('#ffffff')
                                                                                ->columnSpan(6),
                                                                        ])
                                                                ])
                                                        ])
                                                        ->minItems(0)
                                                        ->maxItems(3)
                                                        ->defaultItems(1)
                                                        ->reorderableWithButtons()
                                                        ->collapsed()
                                                        ->cloneable()
                                                        ->addActionLabel('➕ Thêm nút mới')
                                                        ->deletable('🗑️ Xóa nút')
                                                        ->reorderable('↕️ Sắp xếp lại')
                                                        ->itemLabel(fn (array $state): ?string =>
                                                        $state['text'] ? '🔘 ' . $state['text'] : '🔘 Nút mới'
                                                        )
                                                        ->columnSpanFull(),
                                                ]),

                                            // Step 4: Hiển thị và Tương tác
                                            Forms\Components\Wizard\Step::make('Hiển thị')
                                                ->icon('heroicon-o-eye')
                                                ->schema([
                                                    Forms\Components\Section::make('⏱️ Thời điểm hiển thị')
                                                        ->schema([
                                                            Forms\Components\Grid::make(3)
                                                                ->schema([
                                                                    Forms\Components\Select::make('popup.display_mode')
                                                                        ->label('Chế độ hiển thị')
                                                                        ->options([
                                                                            'auto' => '⚡ Ngay lập tức',
                                                                            'delay' => '⏰ Sau khoảng thời gian',
                                                                            'scroll' => '📜 Khi cuộn trang',
                                                                            'exit' => '🚪 Khi rời trang',
                                                                            'manual' => '👆 Thủ công (qua nút)',
                                                                        ])
                                                                        ->default('auto')
                                                                        ->reactive()
                                                                        ->columnSpan(3),

                                                                    Forms\Components\TextInput::make('popup.delay_time')
                                                                        ->label('⏱️ Thời gian trễ')
                                                                        ->numeric()
                                                                        ->suffix('giây')
                                                                        ->default(3)
                                                                        ->minValue(1)
                                                                        ->maxValue(60)
                                                                        ->visible(fn (callable $get) => $get('popup.display_mode') === 'delay')
                                                                        ->columnSpan(3),

                                                                    Forms\Components\TextInput::make('popup.scroll_percentage')
                                                                        ->label('📊 Phần trăm cuộn')
                                                                        ->numeric()
                                                                        ->suffix('%')
                                                                        ->default(50)
                                                                        ->minValue(0)
                                                                        ->maxValue(100)
                                                                        ->visible(fn (callable $get) => $get('popup.display_mode') === 'scroll')
                                                                        ->columnSpan(3),
                                                                ]),
                                                        ]),

                                                    Forms\Components\Section::make('🎭 Lớp phủ & Hiệu ứng')
                                                        ->schema([
                                                            Forms\Components\Grid::make(3)
                                                                ->schema([
                                                                    Forms\Components\Toggle::make('popup.overlay')
                                                                        ->label('Hiển thị lớp phủ')
                                                                        ->default(true)
                                                                        ->reactive()
                                                                        ->inline(),

                                                                    Forms\Components\ColorPicker::make('popup.overlay_color')
                                                                        ->label('Màu lớp phủ')
                                                                        ->default('#000000')
                                                                        ->visible(fn (callable $get) => $get('popup.overlay')),

                                                                    Forms\Components\TextInput::make('popup.overlay_opacity')
                                                                        ->label('Độ mờ lớp phủ')
                                                                        ->numeric()
                                                                        ->suffix('%')
                                                                        ->default(50)
                                                                        ->minValue(0)
                                                                        ->maxValue(100)
                                                                        ->visible(fn (callable $get) => $get('popup.overlay')),

                                                                    Forms\Components\Select::make('popup.animation')
                                                                        ->label('🎬 Hiệu ứng xuất hiện')
                                                                        ->options([
                                                                            'fade' => 'Mờ dần (Fade)',
                                                                            'slide-up' => 'Trượt lên (Slide Up)',
                                                                            'slide-down' => 'Trượt xuống (Slide Down)',
                                                                            'zoom' => 'Phóng to (Zoom)',
                                                                            'bounce' => 'Nhảy (Bounce)',
                                                                        ])
                                                                        ->default('fade')
                                                                        ->columnSpan(3),
                                                                ]),
                                                        ]),

                                                    Forms\Components\Section::make('⚙️ Tùy chọn khác')
                                                        ->schema([
                                                            Forms\Components\Grid::make(3)
                                                                ->schema([
                                                                    Forms\Components\Toggle::make('popup.show_close_button')
                                                                        ->label('Hiển thị nút đóng (X)')
                                                                        ->default(true)
                                                                        ->inline(),

                                                                    Forms\Components\Toggle::make('popup.close_on_overlay_click')
                                                                        ->label('Đóng khi click ra ngoài')
                                                                        ->default(true)
                                                                        ->inline(),

                                                                    Forms\Components\Toggle::make('popup.close_on_esc')
                                                                        ->label('Đóng khi nhấn ESC')
                                                                        ->default(true)
                                                                        ->inline(),

                                                                    Forms\Components\TextInput::make('popup.show_frequency')
                                                                        ->label('⏳ Hiển thị lại sau')
                                                                        ->numeric()
                                                                        ->suffix('ngày')
                                                                        ->default(1)
                                                                        ->minValue(0)
                                                                        ->helperText('0 = hiển thị mỗi lần truy cập')
                                                                        ->columnSpan(3),
                                                                ]),
                                                        ]),
                                                ]),
                                        ])
                                            ->visible(fn (callable $get) => $get('popup.enabled'))
                                            ->columnSpanFull()
                                            ->persistStepInQueryString()
                                            ->skippable(),
                                    ])
                                    ->columnSpanFull(),
                            ]),

                        // TAB ĐĂNG NHẬP / ĐĂNG KÝ
                        Forms\Components\Tabs\Tab::make('Đăng nhập')
                            ->icon('heroicon-o-user-circle')
                            ->schema([
                                Forms\Components\Section::make('Cài đặt Đăng nhập / Đăng ký')
                                    ->description('Quản lý hiển thị nút đăng nhập và đăng ký trên header của website.')
                                    ->schema([
                                        Forms\Components\Toggle::make('auth_header.enabled')
                                            ->label('Hiển thị nút Đăng nhập / Đăng ký trên header')
                                            ->helperText('Bật để hiển thị nút Đăng nhập / Đăng ký ở thanh header phía client.')
                                            ->default(false)
                                            ->columnSpanFull(),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->persistTabInQueryString()
                    ->columnSpanFull(),

            ])
            ->columns(3)
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = $this->mutateFormDataBeforeSave($this->form->getState());

            $settings = app(static::getSettings());

            // Đọc trạng thái cũ TRƯỚC khi fill
            $wasAuthEnabled  = (bool) ($settings->auth_header['enabled'] ?? false);
            $willAuthEnabled = (bool) ($data['auth_header']['enabled'] ?? false);

            $settings->fill($data);
            $settings->save();

            $fileService = new FileService;
            $fileService->writeFile($this->themePath, $data['theme-editor']);
            $fileService->writeFile($this->twConfigPath, $data['tw-config-editor']);

            // Nếu vừa TẮT tính năng đăng nhập → revoke tất cả Sanctum token
            if ($wasAuthEnabled && !$willAuthEnabled) {
                \Laravel\Sanctum\PersonalAccessToken::whereHasMorph(
                    'tokenable',
                    [\App\Models\User::class]
                )->delete();

                Notification::make()
                    ->title('Đã tắt đăng nhập — tất cả phiên đăng nhập đã bị thu hồi')
                    ->warning()
                    ->send();
            } else {
                Notification::make()
                    ->title('Đã cập nhật cài đặt')
                    ->success()
                    ->send();
            }

            $this->redirect(static::getUrl(), navigate: FilamentView::hasSpaMode() && is_app_url(static::getUrl()));
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    // Trước đây hardcode hasRole('super_admin') — permission 'page_ManageGeneral' đã được sinh sẵn
    // nhưng chưa từng được đọc, tick/bỏ tick ở Roles & Permissions không có tác dụng gì.
    public static function canAccess(): bool
    {
        return \Filament\Facades\Filament::auth()->user()?->can('page_ManageGeneral') ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __("Cấu hình web");
    }

    public static function getNavigationLabel(): string
    {
        return __("page.general_settings.navigationLabel");
    }

    public function getTitle(): string|Htmlable
    {
        return __("page.general_settings.title");
    }

    public function getHeading(): string|Htmlable
    {
        return __("page.general_settings.heading");
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __("page.general_settings.subheading");
    }
}