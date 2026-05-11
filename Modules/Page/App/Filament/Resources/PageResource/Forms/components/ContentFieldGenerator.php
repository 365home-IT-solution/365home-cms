<?php

namespace Modules\Page\App\Filament\Resources\PageResource\Forms\Components;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Modules\Product\App\Models\Product;
use Modules\SettingCompany\Entities\Branch;
use TomatoPHP\FilamentIcons\Components\IconPicker;
use Filament\Forms\Components\MarkdownEditor;
use AmidEsfahani\FilamentTinyEditor\TinyEditor;


class ContentFieldGenerator
{
    public function createKeyValuesField($config): KeyValue
    {
        return KeyValue::make("config_values.{$config->id}")
            ->label($config->label)
            ->keyLabel('Từ khóa');
    }

    public function createGroupContactField($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                IconPicker::make('icon')
                    ->label('Icon')
                    ->required()
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Tên liên hệ')
                    ->required()
                    ->placeholder('Nhập tên liên hệ...')
                    ->columnSpanFull(),

                TextInput::make('value')
                    ->label('Giá trị liên hệ')
                    ->required()
                    ->placeholder('Nhập giá trị liên hệ...')
                    ->columnSpanFull(),
            ])
            ->grid(3)
            ->collapsible()
            ->reorderable()
            ->orderColumn('order')
            ->columnSpanFull()
            ->maxItems(4)
            ->defaultItems(1)
            ->addable('Thêm liên hệ mới');
    }

    public function createContentMediaField($config): FileUpload
    {
        return FileUpload::make("config_values.{$config->id}")
            ->label($config->label)
            ->helperText("Chọn ảnh hoặc video...");
    }

    public function createSocialMediaField($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
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
            ->columnSpanFull()
            ->orderColumn('order')
            ->defaultItems(1)
            ->addable('Thêm nền tảng mới');
    }

    public function createContentEditorField($config): TinyEditor
    {
        return TinyEditor::make("config_values.{$config->id}")
            ->label($config->label)
            ->required()
            ->columnSpanFull();
    }


    public function createContentComponent($config): TinyEditor
    {
        return TinyEditor::make("config_values.{$config->id}")
            ->label($config->label)
            ->profile('custom-full')
            ->nullable()
            ->columnSpanFull();
    }

    public function createStatField($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                TextInput::make('count_number')
                    ->label('Số lượng thống kê')
                    ->required()
                    ->placeholder('Nhập số thống kê...')
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Tên')
                    ->required()
                    ->placeholder('Nhập tên...')
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(4)
                    ->required()
                    ->placeholder('Nhập mô tả ...')
                    ->columnSpanFull(),
            ])
            ->grid(3)
            ->collapsible()
            ->columnSpanFull()
            ->orderColumn('order')
            ->defaultItems(1)
            ->addable('Thêm bước mới');
    }


    public function createBusinessBranchField($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                IconPicker::make('icon')
                    ->label('Icon')
                    ->columnSpanFull(),
                
                TextInput::make('title')
                    ->label('Tiêu đề')
                    ->required()
                    ->columnSpanFull(),
                
                Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(3)
                    ->columnSpanFull(),
                
                Select::make('branch')
                    ->label('Chọn chi nhánh')
                    ->required()
                    ->options(function () {
                        return Branch::where('status', true)
                            ->pluck('name', 'id');
                    })
            ])
            ->grid(2)
            ->collapsible()
            ->reorderable()
            ->orderColumn('order')
            ->columnSpanFull()
            ->maxItems(4)
            ->defaultItems(1)
            ->addable('Thêm chi nhánh mới');
    }

    public function createEffectivenessProofField($config): Repeater
    {
        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                Group::make()
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên khách hàng')
                            ->required()
                            ->placeholder('Nhập tên khách hàng...')
                            ->maxLength(255)
                            ->live(onBlur: true),

                        Select::make('gender')
                            ->label('Giới tính')
                            ->options([
                                'male' => 'Nam',
                                'female' => 'Nữ',
                                'other' => 'Khác'
                            ])
                            ->required(),

                        DatePicker::make('treatment_date')
                            ->label('Ngày học')
                            ->required()
                            ->maxDate(now())
                            ->displayFormat('d/m/Y'),
                    ])
                    ->columns(3),

                FileUpload::make('main_image')
                    ->label('Ảnh chính')
                    ->required()
                    ->image()
                    ->maxSize(5120)
                    ->imagePreviewHeight('250')
                    ->columnSpanFull(),

                Grid::make(2)
                    ->schema([
                        Group::make()
                            ->schema([
                                FileUpload::make('before_image')
                                    ->label('Ảnh trước')
                                    ->required()
                                    ->image()
                                    ->maxSize(5120)
                                    ->directory('effectiveness-proofs/before')
                                    ->preserveFilenames()
                                    ->imagePreviewHeight('200'),

                                DatePicker::make('before_date')
                                    ->label('Ngày chụp trước')
                                    ->required()
                                    ->maxDate(now())
                                    ->displayFormat('d/m/Y'),
                            ]),

                        Group::make()
                            ->schema([
                                FileUpload::make('after_image')
                                    ->label('Ảnh sau')
                                    ->required()
                                    ->image()
                                    ->maxSize(5120)
                                    ->imagePreviewHeight('200'),

                                DatePicker::make('after_date')
                                    ->label('Ngày chụp sau')
                                    ->required()
                                    ->maxDate(now())
                                    ->displayFormat('d/m/Y'),
                            ]),
                    ]),
                TextInput::make('address')
                    ->label('Địa chỉ')
                    ->required()
                    ->placeholder('Nhập địa chỉ khách hàng...')
                    ->maxLength(255)
                    ->live(onBlur: true),

                Textarea::make('treatment_plan')
                    ->label('Khóa học')
                    ->required()
                    ->rows(3)
                    ->placeholder('Nhập phương án điều trị...')
                    ->maxLength(1000),
                Select::make('service_type')
                    ->label('Thành tích học')
                    // ->required()
                    ->searchable()
                    ->preload()
                    ->options(function () {
                        return Product::where('is_activated', true)
                            ->pluck('name', 'id');
                    })
                    ->columnSpanFull(),

RichEditor::make('description')
    ->label('Mô tả')
    ->columnSpanFull()
    ->placeholder('Nhập mô tả chi tiết...')
    ->maxLength(1000),

                TagsInput::make('tags')
                    ->label('Tags')
                    ->separator(',')
                    ->suggestions([
                        'Trước điều trị',
                        'Sau điều trị',
                        'Kết quả tốt',
                        'Khách hàng hài lòng'
                    ])
                    ->columnSpanFull(),
            ])
            ->grid(1)
            ->collapsible()
            ->collapsed()
            ->cloneable()
            ->reorderable()
            ->orderColumn('order')
            ->columnSpanFull()
            ->defaultItems(1)
            ->addable('Thêm minh chứng mới')
            ->deletable()
            ->itemLabel(
                fn(array $state): ?string =>
                    $state['name'] ?? null
            )
            ->maxItems(10);
    }

    public function createVideoListField($config): Repeater
    {
        $videoTypes = [
            'youtube' => 'Link YouTube',
            'upload' => 'Upload video'
        ];

        return Repeater::make("config_values.{$config->id}")
            ->label($config->label)
            ->schema([
                Section::make('Thông tin cơ bản')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('customer_name')
                                ->label('Tên khách hàng')
                                ->required()
                                ->placeholder('Nhập tên khách hàng')
                                ->maxLength(255),
                            DatePicker::make('video_date')
                                ->label('Ngày quay')
                                ->required()
                                ->maxDate(now())
                                ->displayFormat('d/m/Y'),
                        ]),
                        Toggle::make('is_main')
                            ->label('Đặt làm video chính')
                            ->default(false)
                            ->reactive()
                            ->afterStateUpdated(function ($state, $set, $component) {
                                if (!$state) return;

                                $livewire = $component->getLivewire();
                                $items = data_get($livewire, $component->getStatePath());

                                if (!is_array($items)) return;

                                foreach ($items as $key => $item) {
                                    if ($key !== $component->getItemKey()) {
                                        data_set($livewire, $component->getStatePath() . ".$key.is_main", false);
                                    }
                                }
                            }),
                    ]),

                Section::make('Nội dung video')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Select::make('video_type')
                            ->label('Nguồn video')
                            ->options($videoTypes)
                            ->default('youtube')
                            ->required()
                            ->reactive(),

                        Group::make()->schema([
                            TextInput::make('youtube_url')
                                ->label('Link YouTube')
                                ->required()
                                ->url()
                                ->placeholder('https://youtube.com/...')
                                ->rules(['regex:/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+$/'])
                                ->helperText('Nhập link video YouTube hợp lệ'),
                            TextInput::make('youtube_thumbnail')
                                ->label('Ảnh thumbnail tùy chỉnh')
                                ->url()
                                ->placeholder('https://...')
                                ->helperText('Để trống để dùng thumbnail mặc định'),
                        ])->visible(fn ($get) => $get('video_type') === 'youtube'),

                        Group::make()->schema([
                            FileUpload::make('thumbnail')
                                ->label('Tải lên ảnh nền')
                                ->required()
                                ->maxSize(5000)
                                ->acceptedFileTypes(['image/png', 'application/pdf'])
                                ->helperText('Định dạng: PNG, PDF. Tối đa: 5MB'),

                            FileUpload::make('video')
                                ->label('Tải lên video')
                                ->required()
                                ->acceptedFileTypes(['video/mp4', 'video/quicktime'])
                                ->maxSize(102400)
                                ->helperText('Định dạng: MP4, MOV. Tối đa: 100MB'),
                        ])->visible(fn ($get) => $get('video_type') === 'upload'),
                    ]),

                Section::make('Thông tin mô tả')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('title')
                            ->label('Tiêu đề')
                            ->required()
                            ->placeholder('Nhập tiêu đề video')
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Mô tả')
                            ->rows(3)
                            ->placeholder('Mô tả chi tiết về video')
                            ->maxLength(1000),
                        Grid::make(2)->schema([
                            Select::make('service_type')
                                ->label('Loại dịch vụ')
                                ->searchable()
                                ->preload()
                                ->options(fn() => Product::where('is_activated', true)->pluck('name', 'id')),
                            TagsInput::make('tags')
                                ->label('Tags')
                                ->separator(',')
                                ->suggestions([
                                    'Khách hàng',
                                    'Review',
                                    'Trải nghiệm',
                                    'Phản hồi tích cực'
                                ]),
                        ]),
                    ]),
            ])
            ->grid(2)
            ->collapsible()
            ->collapsed()
            ->cloneable()
            ->reorderable()
            ->orderColumn('order')
            ->columnSpanFull()
            ->defaultItems(1)
            ->deletable()
            ->itemLabel(fn($state) =>
                ($state['is_main'] ? '📹 ' : '🎥 ') .
                ($state['customer_name'] ?? 'Video mới') .
                ' (' . ($state['video_type'] === 'youtube' ? 'YouTube' : 'Upload') . ')'
            )
            ->mutateRelationshipDataBeforeCreateUsing(function (array $data) {
                static $hasMain = false;
                if ($data['is_main']) {
                    if ($hasMain) $data['is_main'] = false;
                    $hasMain = true;
                }
                return $data;
            })
            ->addable('+ Thêm video mới');
    }
}