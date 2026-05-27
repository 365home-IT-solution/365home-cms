<?php

namespace Modules\Page\App\Filament\Resources\PageResource\Forms\Components;

use AmidEsfahani\FilamentTinyEditor\TinyEditor;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use TomatoPHP\FilamentIcons\Components\IconPicker;
use Filament\Forms\Components\RichEditor;


class BuilderFieldGenerator
{

    public function createChooseRoom($config): Section
    {
        $configId = $config->id;
        $settings = app(\App\Settings\GeneralSettings::class);
        $theme = $settings->site_theme;
        $primaryColor = $theme['primary'] ?? '#3b82f6';
        $grayColor = $theme['gray'] ?? '#e5e7eb';

        return Section::make($config->label)
            ->schema([
                Repeater::make("config_values.{$configId}.categories")
                    ->label($config->label)
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                // Chọn danh mục
                                Select::make('category_id')
                                    ->label('Danh mục')
                                    ->placeholder('Chọn Vị trí ')
                                    ->options(
                                        Category::where('category_type', 'product')
                                            ->pluck('name', 'id')
                                            ->toArray()
                                    )
                                    ->reactive()
                                    ->afterStateUpdated(fn (callable $set) => $set('products', []))
                                    ->required()
                                    ->columnSpan(12),

                                // Chọn sản phẩm
                                Select::make('products')
                                    ->label('Phòng')
                                    ->placeholder('Chọn phòng hiển thị')
                                    ->multiple()
                                    ->options(function (callable $get) {
                                        $categoryId = $get('category_id');
                                        if ($categoryId) {
                                            return Product::whereHas('categories', function ($query) use ($categoryId) {
                                                $query->where('category_id', $categoryId);
                                            })->pluck('name', 'id')->toArray();
                                        }
                                        return [];
                                    })
                                    ->hidden(fn (callable $get) => !$get('category_id'))
                                    ->required()
                                    ->columnSpan(12),
                            ])
                    ])
                    ->minItems(0)
                    ->columns(12)
                    ->grid(3)
                    ->reorderableWithButtons() // Sử dụng nút kéo thả giống testimonial
                    ->collapsed() // Thu gọn các mục repeater
                    ->cloneable() // Cho phép sao chép mục
                    ->addActionLabel('Thêm danh mục') // Nhãn nút thêm
                    ->columnSpan(12),
            ])
            ->columns(12)
            ->extraAttributes([
                'style' => "border: 1px solid {$primaryColor}; border-radius: 12px; padding: 24px; background-color: #ffffff;"
            ]);
    }
    public function createTestimonial($config): Repeater
        {
            return Repeater::make("config_values.{$config->id}")
                ->label($config->label)
                ->schema([
                    TextInput::make('title')
                        ->label('Tiêu đề')
                        ->columnSpan(12),
                    Grid::make(2)
                        ->schema([
                            FileUpload::make('image')
                                ->label('Ảnh khách hàng')
                                ->avatar()
                                ->image()
                                ->imageEditor()
                                ->alignCenter(),
    
                            FileUpload::make('brand_image')
                                ->label('Ảnh công ty khách hàng (nếu có)')
                                ->avatar()
                                ->image()
                                ->imageEditor()
                                ->alignCenter(),
                        ])->columnSpan(12),
    
                    TextInput::make('user_name')
                        ->label('Tên khách hàng')
                        ->columnSpan(4),
    
                    TextInput::make('role')
                        ->label('Vai trò khách hàng')
                        ->columnSpan(4),
    
                    Select::make('start')
                        ->label('Đánh giá sao')
                        ->options([
                            1 => '1 sao',
                            2 => '2 sao',
                            3 => '3 sao',
                            4 => '4 sao',
                            5 => '5 sao'
                        ])
                        ->columnSpan(4),
    
                    Textarea::make('content')
                        ->label('Nội dung')
                        ->rows(3)
                        ->columnSpan(12),
                ])
                ->reorderableWithButtons()
                ->collapsed()
                ->cloneable()
                ->addActionLabel('Thêm một nội dung')
                ->columnSpan(12);
        }

    public function createBannerComponent($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                // Section cho hình ảnh
                Section::make('Hình ảnh')
                    ->schema([
                        FileUpload::make('logo')
                            ->label('Logo trung tâm')
                            ->image()
                            ->required()
                            ->directory('banners/logos'),
                        FileUpload::make('surrounding_images')
                            ->label('4 ảnh xung quanh logo')
                            ->multiple()
                            ->maxFiles(4)
                            ->image()
                            ->required()
                            ->directory('banners/surrounding'),
                        FileUpload::make('service_images')
                            ->label('2 ảnh dịch vụ')
                            ->multiple()
                            ->maxFiles(2)
                            ->image()
                            ->required()
                            ->directory('banners/services'),
                    ])
                    ->collapsible(),

                // Section cho nội dung văn bản
                Section::make('Nội dung')
                    ->schema([
                        TextInput::make('small_title')
                            ->label('Tiêu đề nhỏ')
                            ->default('GIỚI THIỆU DOANH NGHIỆP')
                            ->required(),
                        TextInput::make('large_title')
                            ->label('Tiêu đề lớn')
                            ->required(),
                        Textarea::make('description')
                            ->label('Nội dung mô tả')
                            ->rows(5)
                            ->required(),
                    ])
                    ->collapsible(),

                // Section cho phương châm hoạt động
                Section::make('Phương châm hoạt động')
                    ->schema([
                        TextInput::make('motto_years')
                            ->label('Số năm')
                            ->numeric()
                            ->required(),
                        TextInput::make('motto_text')
                            ->label('Nội dung phương châm')
                            ->required(),
                    ])
                    ->collapsible(),

                // Section cho 2 dịch vụ kèm mô tả
                Repeater::make('services')
                    ->label('Dịch vụ')
                    ->schema([
                        TextInput::make('service_description')
                            ->label('Mô tả dịch vụ')
                            ->required(),
                    ])
                    ->maxItems(2)
                    ->defaultItems(2)
                    ->collapsible(),

                // Section cho button
                Section::make('Button')
                    ->schema([
                        TextInput::make('button_text')
                            ->label('Tên button')
                            ->required(),
                        TextInput::make('button_link')
                            ->label('Link button')
                            ->url()
                            ->required(),
                    ])
                    ->collapsible(),
            ])
            ->reorderableWithButtons()
            ->collapsed()
            ->cloneable()
            ->addActionLabel('Thêm một nội dung')
            ->columnSpan(12);
    }

    public static function createBusinessIntroduction($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                // Tiêu đề đầu và màu
                Grid::make(2) // Sử dụng Grid để sắp xếp 2 cột
                ->schema([
                    TextInput::make('header_label')
                        ->label('Tiêu đề đầu')
                        ->default('THÔNG TIN CHUNG')
                        ->required(),
                    ColorPicker::make('header_color')
                        ->label('Màu Tiêu đề đầu')
                        ->default('#eb7f19')
                        ->required(),
                    TextInput::make('title_label')
                        ->label('Tiêu đề 2')
                        ->default('GIỚI THIỆU DOANH NGHIỆP')
                        ->required(),
                    ColorPicker::make('title_color')
                        ->label('Màu Tiêu đề 2')
                        ->default('#04853a')
                        ->required(),
                ]),

                // Repeater con cho các mục thông tin (bỏ giới hạn 1 mục)
                Repeater::make('items')
                    ->label('Thông tin doanh nghiệp')
                    ->schema([
                        Grid::make(2) // Sử dụng Grid để sắp xếp 2 cột
                        ->schema([
                            TextInput::make('key')
                                ->label('Tiêu đề')
                                ->placeholder('Ví dụ: Tên tiếng Việt, Trụ sở chính...')
                                ->required(),
                            Select::make('value_type')
                                ->label('Loại giá trị')
                                ->options([
                                    'text' => 'Văn bản',
                                    'image' => 'Hình ảnh',
                                ])
                                ->default('text')
                                ->reactive()
                                ->required(),
                            TextInput::make('value_text')
                                ->label('Giá trị (Văn bản)')
                                ->placeholder('Nhập nội dung văn bản')
                                ->visible(fn ($get) => $get('value_type') === 'text')
                                ->required(fn ($get) => $get('value_type') === 'text'),
                            FileUpload::make('value_image')
                                ->label('Giá trị (Hình ảnh)')
                                ->image()
                                ->directory('business-introduction')
                                ->visible(fn ($get) => $get('value_type') === 'image')
                                ->required(fn ($get) => $get('value_type') === 'image'),
                            ColorPicker::make('value_color')
                                ->label('Màu giá trị (Văn bản)')
                                ->default('#000000')
                                ->visible(fn ($get) => $get('value_type') === 'text'),
                        ]),
                    ])
                    ->reorderableWithButtons()
                    ->collapsed()
                    ->cloneable()
                    ->addActionLabel('Thêm một nội dung')
                    ->columnSpan(12),
            ])
            ->reorderableWithButtons()
            ->collapsed()
            ->cloneable()
            ->addActionLabel('Thêm một nội dung')
            ->maxItems(1) // Giữ giới hạn 1 mục cho Repeater chính
            ->columnSpan(12);
    }

    public static function createPerformanceResult($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                Grid::make(12)
                    ->schema([
                        Card::make([
                            TextInput::make('value')
                                ->label('Giá trị')
                                ->numeric()
                                ->step(0.01)
                                ->required()
                                ->columnSpan(4),

                            TextInput::make('unit')
                                ->label('Đơn vị (VD: Tấn, Triệu Sm³)')
                                ->placeholder('Nhập đơn vị...')
                                ->required()
                                ->columnSpan(4),

                            TextInput::make('label')
                                ->label('Tên chỉ số')
                                ->placeholder('VD: Sản lượng LPG')
                                ->required()
                                ->columnSpan(4),
                        ])
                            ->columns(12)
                            ->columnSpanFull()
                            ->extraAttributes([
                                'class' => 'bg-white border border-gray-200 rounded-lg shadow-md p-4 hover:shadow-lg transition-all duration-300',
                            ]),
                    ]),
            ])
            ->cloneable()
            ->addActionLabel('Thêm chỉ số')
            ->columnSpanFull();
    }

    public static function createVisionSection($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                TextInput::make('title')
                    ->label('Tiêu đề chính (ví dụ: Giá trị cốt lõi)')
                    ->required(),

                TextInput::make('youtube_url')
                    ->label('URL video YouTube')
                    ->url()
                    ->placeholder('https://www.youtube.com/embed/...'),

                FileUpload::make('header_logo')
                    ->label('Ảnh logo kỷ niệm 25 năm')
                    ->image()
                    ->required(),

                FileUpload::make('company_logo')
                    ->label('Logo công ty')
                    ->image()
                    ->required(),

                FileUpload::make('bottom_logo')
                    ->label('Logo GAS góc dưới')
                    ->image()
                    ->required(),

                Repeater::make('sections')
                    ->label('Danh sách nội dung (Tầm nhìn, Sứ mệnh, Giá trị cốt lõi)')
                    ->schema([
                        FileUpload::make('image')
                            ->label('Ảnh minh hoạ')
                            ->image()
                            ->required(),

                        TextInput::make('heading')
                            ->label('Tiêu đề')
                            ->required(),

                        Textarea::make('description')
                            ->label('Mô tả')
                            ->rows(4)
                            ->required(),
                    ])
                    ->minItems(3)
                    ->maxItems(3)
                    ->defaultItems(3)
                    ->reorderable()
                    ->cloneable(false),
            ])
            ->cloneable()
            ->addActionLabel('Thêm phiên bản khác (nếu cần)')
            ->columnSpanFull();
    }

    public function createChartComponent($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                Repeater::make('time_periods')
                    ->label('Danh sách thời gian')
                    ->schema([
                        TextInput::make('month')
                            ->label('Tháng/Năm')
                            ->required()
                            ->placeholder('Ví dụ: Tháng 11/2024')
                            ->maxLength(50),

                        Repeater::make('gas_data')
                            ->label('Thông tin Gas theo khu vực và loại bình')
                            ->schema([
                                Select::make('region')
                                    ->label('Khu vực')
                                    ->options([
                                        'duyen_hai_mien_trung' => 'Duyên Hải Miền Trung',
                                        'tay_nguyen' => 'Tây Nguyên',
                                        'tay_nam_bo' => 'Tây Nam Bộ',
                                        'dong_nam_bo' => 'Đông Nam Bộ',
                                    ])
                                    ->required(),

                                Select::make('gas_type')
                                    ->label('Loại Gas')
                                    ->options([
                                        '12kg' => 'Bình 12KG',
                                        '45kg' => 'Bình 45KG',
                                    ])
                                    ->required(),

                                TextInput::make('value')
                                    ->label('Giá (VND)')
                                    ->numeric()
                                    ->required()
                                    ->suffix('VND')
                                    ->placeholder('Ví dụ: 350000'),

                                Select::make('type')
                                    ->label('Kiểu biểu đồ')
                                    ->options([
                                        'bar' => 'Cột (Bar)',
                                        'line' => 'Đường (Line)',
                                    ])
                                    ->default(fn (callable $get) => $get('gas_type') === '45kg' ? 'line' : 'bar')
                                    ->required(),

                                ColorPicker::make('color')
                                    ->label('Màu sắc')
                                    ->required()
                                    ->default(function (callable $get) {
                                        $region = $get('region');
                                        $type = $get('gas_type');
                                        return match ("{$region}_{$type}") {
                                            'duyen_hai_mien_trung_12kg' => '#3B82F6',
                                            'duyen_hai_mien_trung_45kg' => '#60A5FA',
                                            'tay_nguyen_12kg' => '#14B8A6',
                                            'tay_nguyen_45kg' => '#0D9488',
                                            'tay_nam_bo_12kg' => '#EF4444',
                                            'tay_nam_bo_45kg' => '#F87171',
                                            'dong_nam_bo_12kg' => '#FBBF24',
                                            'dong_nam_bo_45kg' => '#FACC15',
                                            default => '#A3A3A3',
                                        };
                                    }),
                            ])
                            ->addActionLabel('Thêm loại Gas')
                            ->itemLabel(fn (array $state): string => ($state['region'] ?? '...') . ' - Bình ' . ($state['gas_type'] ?? '...'))
                            ->defaultItems(8)
                            ->columnSpanFull(),
                    ])
                    ->addActionLabel('Thêm thời gian')
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => $state['month'] ?? 'Thời gian mới')
                    ->defaultItems(1)
                    ->columnSpanFull(),
            ])
            ->columnSpanFull()
            ->addActionLabel('Thêm cấu hình biểu đồ')
            ->collapsible()
            ->defaultItems(1)
            ->itemLabel(fn (array $state): string => 'Cấu hình: ' . ($state['time_periods'][0]['month'] ?? 'Chưa đặt thời gian'));
    }

    public function createOverview($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                TextInput::make('system_type')
                    ->label('Loại hệ thống')
                    ->required()
                    ->placeholder('LPG hoặc CNG'),

                Repeater::make('sections')
                    ->label('Các thống kê')
                    ->schema([
                        TextInput::make('title')
                            ->label('Tiêu đề')
                            ->required(),
                        TextInput::make('value')
                            ->label('Giá trị số liệu')
                            ->required(),
                        TextInput::make('subtitle')
                            ->label('Mô tả chi tiết')
                            ->placeholder('TỔNG SỨC CHỨA, CÔNG SUẤT, v.v...'),
                        TextInput::make('unit')
                            ->label('Đơn vị')
                            ->placeholder('TẤN, TẤN/THÁNG, Sm3/h, v.v...'),
                        ColorPicker::make('highlight_color')
                            ->label('Màu nhấn')
                            ->default('#ff7f00')
                    ])
                    ->columns(2)
                    ->reorderableWithButtons()
                    ->collapsed()
                    ->cloneable()
                    ->addActionLabel('Thêm một thống kê')
            ])
            ->reorderableWithButtons()
            ->collapsed()
            ->cloneable()
            ->addActionLabel('Thêm một loại hệ thống')
            ->columnSpan(12);
    }

    public function createBranch($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label('Cấu hình vùng và chi nhánh')
            ->schema([
                TextInput::make('region_name')
                    ->label('Tên vùng')
                    ->required()
                    ->helperText('Nhập tên vùng địa lý (VD: Miền Bắc, Miền Trung, Miền Nam)'),

                TextInput::make('region_code')
                    ->label('Mã vùng')
                    ->helperText('Nhập mã vùng nếu có (không bắt buộc)'),

                Repeater::make('branches')
                    ->label('Chi nhánh trong vùng')
                    ->schema($this->getBranchSchema())
                    ->itemLabel(function (array $state): ?string {
                        $label = $state['name'] ?? ($state['province'] ?? 'Chi nhánh mới');

                        return $label;
                    })
                    ->collapsible()
                    ->defaultItems(1)
                    ->reorderable()
                    ->addActionLabel('Thêm chi nhánh')
                    ->helperText('Thêm nhiều chi nhánh cùng lúc cho vùng này'),
            ])
            ->itemLabel(fn (array $state): ?string =>
                $state['region_name'] ?? 'Vùng mới'
            )
            ->collapsible()
            ->defaultItems(0)
            ->reorderable()
            ->columnSpanFull()
            ->addActionLabel('Thêm vùng mới')
            ->helperText('Thêm vùng mới với các chi nhánh');
    }

    public function createRepeater($config): Repeater
    {
         return Repeater::make("config_values.{$config->id}")
        ->label('Chuỗi nội dung')
        ->schema([
            // Bên trong 1 item chia 2 cột
               
                    FileUpload::make('image')
                        ->label('Ảnh')
                        ->image()
                        ->directory('repeater-component')
                        ->columnSpanFull(),

                    TextInput::make('branch')
                        ->label('Chi nhánh')
                        ->placeholder('Chi nhánh: Ninh Kiều, Cần Thơ')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    TextInput::make('title')
                        ->label('Tiêu đề')
                        ->placeholder('Home - Đại Ngân, Ninh Kiều')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Textarea::make('desciption')
                        ->label('Mô tả')
                        ->placeholder('')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    TextInput::make('button')
                        ->label('Tên nút')
                        ->placeholder('Khám phá ngay')
                        ->columnSpanFull(),

                    TextInput::make('url')
                        ->label('Điều hướng')
                        ->placeholder('https://')
                        ->columnSpanFull(),
             
        ])
        ->collapsible()
        ->grid(3)
        ->reorderable()
        ->columnSpanFull()
        ->addActionLabel('Thêm nội dung')
        ->helperText('');
    }

    public function createServiceIcon($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label('Cấu hình Giá trị cốt lõi')
            ->schema([
                Section::make('Thông tin chính')
                    ->schema([
                        FileUpload::make('anniversary_logo')
                            ->label('Ảnh Logo Kỷ Niệm')
                            ->image()
                            ->directory('core-values')
                            ->required()
                            ->helperText('Tải lên ảnh logo kỷ niệm, ví dụ: "25 Năm".'),
                        TextInput::make('title')
                            ->label('Tiêu đề')
                            ->default('GIÁ TRỊ CỐT LÕI')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('subtitle')
                            ->label('Tiêu đề phụ')
                            ->default('Những giá trị định hình con đường phát triển bền vững của chúng tôi suốt 25 năm qua')
                            ->rows(3)
                            ->maxLength(500),
                    ])
                    ->columns(2),

                Section::make('Các giá trị cốt lõi')
                    ->schema([
                        Repeater::make('core_values')
                            ->label('Danh sách giá trị cốt lõi')
                            ->schema([
                                IconPicker::make('icon')
                                    ->label('Icon')
                                    ->required(),
                                TextInput::make('title')
                                    ->label('Tiêu đề')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Ví dụ: Chất Lượng'),
                                Textarea::make('description')
                                    ->label('Mô tả')
                                    ->required()
                                    ->rows(3)
                                    ->maxLength(500)
                                    ->placeholder('Ví dụ: Cam kết chất lượng hàng đầu...'),
                            ])
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable()
                            ->addActionLabel('Thêm giá trị mới')
                            ->helperText('Thêm các giá trị cốt lõi với icon, tiêu đề và mô tả.'),
                    ]),
            ])
            ->collapsible()
            ->defaultItems(0)
            ->maxItems(1) // Giới hạn chỉ 1 section "Giá trị cốt lõi"
            ->reorderable()
            ->columnSpanFull()
            ->addActionLabel('') // Để trống như yêu cầu
            ->helperText(''); // Để trống như yêu cầu
    }
    private function getBranchSchema(): array
    {
        return [
            TextInput::make('name')
                ->label('Đơn vị')
                ->required()
                ->helperText('Tên chi nhánh/đơn vị'),

            TextInput::make('province')
                ->label('Tỉnh/Thành phố')
                ->required()
                ->helperText('Thuộc tỉnh/thành phố nào'),

            Textarea::make('address')
                ->label('Địa chỉ')
                ->required()
                ->rows(3)
                ->helperText('Địa chỉ đầy đủ của chi nhánh'),

            TextInput::make('phone')
                ->label('Điện thoại')
                ->tel()
                ->required()
                ->helperText('Số điện thoại liên hệ'),

            TextInput::make('fax')
                ->label('Fax')
                ->helperText('Số fax (nếu có)'),

            ColorPicker::make('color')
                ->label('Màu nhận diện')
                ->helperText('Chọn màu để nhận diện chi nhánh này')
                ->rgba()
                ->default('#1E88E5'),

            Toggle::make('is_main')
                ->label('Chi nhánh chính')
                ->default(false)
                ->helperText('Đánh dấu nếu đây là chi nhánh chính của vùng'),
        ];
    }

    public function createBuilderField($config): Builder
    {
        return Builder::make("config_values.{$config->id}")
            ->label($config->label)
            ->blocks([
                Builder\Block::make('banner')
                    ->label('Băng chuyền')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                FileUpload::make("image")
                                    ->image()
                                    ->label('Hình ảnh')
                                    ->columnSpan(1),
                                Group::make([
                                    TextInput::make('title')
                                        ->label('Tiêu đề'),
                                    TextInput::make('description')
                                        ->label('Mô tả'),
                                    TextInput::make('cta_text')
                                        ->label('Văn bản CTA'),
                                    TextInput::make('cta_link')
                                        ->label('Liên kết CTA')
                                        ->url()
                                        ->suffixIcon('heroicon-m-link'),
                                ])->columnSpan(1),
                            ]),
                    ]),
            ])
            ->columnSpanFull()
            ->addActionLabel('Thêm phần mới')
            ->collapsible();
    }

    public function createSidebarBuilder($config): Builder
    {
        return Builder::make("config_values.{$config->id}")
            ->blockPickerColumns(1)
            ->label($config->label)
            ->blocks([
                Builder\Block::make('social_media')
                    ->label('Mạng xã hội')
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                TextInput::make('title')
                                    ->label('Tiêu đề')
                                    ->default('Mạng xã hội')
                                    ->placeholder('Nhập tiêu đề...')
                                    ->required(),
                                Repeater::make('social_media')
                                    ->label('Mạng xã hội')
                                    ->schema([
                                        Select::make('platform')
                                            ->label('Nền tảng')
                                            ->options([
                                                'facebook' => 'Facebook',
                                                'twitter' => 'Twitter',
                                                'instagram' => 'Instagram',
                                                'linkedin' => 'LinkedIn',
                                                'youtube' => 'YouTube',
                                            ])
                                            ->reactive()
                                            ->afterStateUpdated(fn($state, callable $set) => $set('icon', match ($state) {
                                                'facebook' => 'fab fa-facebook-f',
                                                'twitter' => 'fab fa-twitter',
                                                'instagram' => 'fab fa-instagram',
                                                'linkedin' => 'fab fa-linkedin-in',
                                                'youtube' => 'fab fa-youtube',
                                                default => null,
                                            })),
                                        Hidden::make('icon'),
                                        TextInput::make('url')
                                            ->label('URL')
                                            ->url()
                                            ->suffixIcon('heroicon-m-link'),
                                    ])
                                    ->grid(3)
                                    ->collapsible()
                                    ->itemLabel(fn(array $state): ?string => $state['platform'] ? ucfirst($state['platform']) : 'Nền tảng mới')
                                    ->columnSpanFull()
                                    ->addActionLabel('Thêm nền tảng')
                                    ->orderColumn('order')
                                    ->defaultItems(1),
                            ])
                    ])
                    ->icon('heroicon-m-globe-alt')
                    ->columnSpan(1),

                Builder\Block::make('categories')
                    ->label('Danh mục')
                    ->schema([
                        TextInput::make('title')
                            ->label('Tiêu đề')
                            ->default('Danh mục bài viết')
                            ->placeholder('Nhập tiêu đề...')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->icon('heroicon-m-bars-4')
                    ->columnSpan(1),

                Builder\Block::make('search')
                    ->label('Tìm kiếm')
                    ->schema([
                        TextInput::make('title')
                            ->label('Tiêu đề')
                            ->default('Tìm kiếm')
                            ->placeholder('Nhập tiêu đề...')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->icon('heroicon-m-document-magnifying-glass')
                    ->columnSpan(1),

                Builder\Block::make('new_post')
                    ->label('Bài viết mới')
                    ->schema([
                        TextInput::make('title')
                            ->label('Tiêu đề')
                            ->default('Bài viết mới nhất')
                            ->placeholder('Nhập tiêu đề...')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->icon('heroicon-m-newspaper')
                    ->columnSpan(1),
            ])
            ->columns(3)
            ->columnSpanFull()
            ->blockNumbers(false)
            ->addActionLabel('Thêm vào thanh bên')
            ->collapsible();
    }

    public function createServiceField($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                FileUpload::make('icon')
                    ->label('Icon')
                    ->imageEditor()
                    ->image()
                    ->required()
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Tên dịch vụ')
                    ->required()
                    ->placeholder('Nhập tên dịch vụ...')
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(4)
                    ->required()
                    ->placeholder('Nhập mô tả dịch vụ...')
                    ->columnSpanFull(),
            ])
            ->grid(3)
            ->collapsible()
            ->columnSpanFull()
            ->orderColumn('order')
            ->defaultItems(1)
            ->addable('Thêm bước mới');
    }
}