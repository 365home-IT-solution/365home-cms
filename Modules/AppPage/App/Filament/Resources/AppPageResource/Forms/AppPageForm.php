<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Filament\Resources\AppPageResource\Forms;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\Str;
use Modules\AppPage\App\Models\Banner;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class AppPageForm
{
    public static function form(Form $form): Form
    {
        return $form->columns(1)->schema([
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('name')
                    ->label('Tên trang')
                    ->placeholder('VD: Trang chủ')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state, '_'))),

                TextInput::make('slug')
                    ->label('Slug (API key)')
                    ->placeholder('VD: home')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100)
                    ->helperText('/api/pages/{slug}'),
            ]),

            Grid::make(['default' => 1, 'sm' => 2])->schema([
                Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(2)
                    ->maxLength(500),

                Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->default(true)
                    ->inline(false),
            ]),

            Builder::make('content')
                ->label('Nội dung trang')
                ->addActionLabel('+ Thêm block')
                ->collapsible()
                ->cloneable()
                ->reorderable()
                ->blocks([
                    Builder\Block::make('heading')
                        ->label('Tiêu đề')
                        ->icon('heroicon-o-bars-3-bottom-left')
                        ->schema([
                            TextInput::make('text')
                                ->label('Nội dung tiêu đề')
                                ->placeholder('VD: Phòng nổi bật tháng 5')
                                ->required(),
                        ]),

                    Builder\Block::make('banner')
                        ->label('Banner')
                        ->icon('heroicon-o-photo')
                        ->schema([
                            Repeater::make('items')
                                ->label('Danh sách banner')
                                ->schema([
                                    Select::make('banner_id')
                                        ->label('Chọn banner')
                                        ->options(fn () => Banner::where('is_active', true)
                                            ->orderBy('title')
                                            ->get()
                                            ->mapWithKeys(fn ($b) => [$b->id => $b->title ?: "(ID #{$b->id})"])
                                            ->toArray())
                                        ->searchable()
                                        ->required()
                                        ->columnSpanFull(),
                                ])
                                ->addActionLabel('+ Thêm banner')
                                ->reorderable()
                                ->collapsible()
                                ->collapsed()
                                ->itemLabel(fn (array $state): ?string => isset($state['banner_id'])
                                    ? (Banner::find($state['banner_id'])?->title ?: "(ID #{$state['banner_id']})")
                                    : 'Chọn banner...')
                                ->columnSpanFull(),
                        ]),

                    Builder\Block::make('room_list')
                        ->label('Danh sách phòng')
                        ->icon('heroicon-o-home')
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('title')
                                    ->label('Tiêu đề section')
                                    ->placeholder('VD: Phòng siu Deal tháng 5')
                                    ->required(),

                                TextInput::make('subtitle')
                                    ->label('Phụ đề')
                                    ->placeholder('VD: Giá tốt nhất hôm nay'),
                            ]),

                            Grid::make(3)->schema([
                                Select::make('layout')
                                    ->label('Layout')
                                    ->options([
                                        'horizontal_scroll' => 'Cuộn ngang',
                                        'grid'              => 'Lưới',
                                        'featured'          => 'Nổi bật',
                                    ])
                                    ->default('horizontal_scroll')
                                    ->required(),

                                Toggle::make('show_arrow')
                                    ->label('Hiện mũi tên →')
                                    ->default(true)
                                    ->inline(false),

                                TextInput::make('view_all_url')
                                    ->label('URL "Xem tất cả"')
                                    ->placeholder('/rooms?type=deal'),
                            ]),

                            Select::make('display_mode')
                                ->label('Chế độ hiển thị')
                                ->options([
                                    'fixed'     => 'Cố định — luôn hiển thị dù đổi khu vực',
                                    'by_region' => 'Theo khu vực — ẩn nếu không có khu vực / không có phòng',
                                ])
                                ->default('fixed')
                                ->required()
                                ->live()
                                ->helperText('Cố định: hiển thị tất cả phòng đã chọn. Theo khu vực: ẩn section khi guest/user chưa chọn khu vực hoặc khu vực đó không có phòng.')
                                ->columnSpanFull(),

                            Select::make('region_content_type')
                                ->label('Nội dung hiển thị theo khu vực')
                                ->options([
                                    'rooms'    => 'Phòng',
                                    'branches' => 'Chi nhánh',
                                ])
                                ->default('rooms')
                                ->required()
                                ->helperText('Phòng: danh sách phòng của khu vực (như hiện tại). Chi nhánh: danh sách chi nhánh của khu vực.')
                                ->columnSpanFull()
                                ->visible(fn (Get $get) => ($get('display_mode') ?? 'fixed') === 'by_region'),

                            Select::make('branch_ids')
                                ->label('Chọn chi nhánh')
                                ->options(fn () => Category::whereNull('parent_id')
                                    ->where('category_type', 'product')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray())
                                ->multiple()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(fn (callable $set) => $set('product_ids', []))
                                ->placeholder('Tất cả chi nhánh...')
                                ->hidden(fn (Get $get) => ($get('display_mode') ?? 'fixed') === 'by_region'),

                            Select::make('product_ids')
                                ->label('Chọn phòng')
                                ->helperText('Để trống = hiển thị tất cả phòng. Tab lọc vẫn áp dụng theo loại phòng.')
                                ->multiple()
                                ->searchable()
                                ->options(function (Get $get) {
                                    $query = Product::where('is_activated', true);

                                    $branchIds = array_filter((array) ($get('branch_ids') ?? []));
                                    if (! empty($branchIds)) {
                                        $childIds  = Category::whereIn('parent_id', $branchIds)->pluck('id');
                                        $filterIds = collect($branchIds)->merge($childIds)->unique()->values();

                                        $query->whereHas(
                                            'categories',
                                            fn ($cq) => $cq->whereIn('category_id', $filterIds)
                                        );
                                    }

                                    return $query->orderBy('name')->pluck('name', 'id')->toArray();
                                })
                                ->placeholder('Để trống để hiển thị tất cả phòng...')
                                ->hidden(fn (Get $get) => ($get('display_mode') ?? 'fixed') === 'by_region'),
                        ]),

                    Builder\Block::make('suggestion_list')
                        ->label('Gợi ý điểm đến')
                        ->icon('heroicon-o-light-bulb')
                        ->schema([
                            Select::make('type')
                                ->label('Loại gợi ý')
                                ->options([
                                    'branch' => 'Chi nhánh',
                                    'room'   => 'Phòng',
                                ])
                                ->default('room')
                                ->required()
                                ->helperText('Hiển thị theo khu vực đã chọn. Nếu chưa chọn khu vực sẽ thông báo người dùng chọn.'),
                        ]),

                    Builder\Block::make('promotion_list')
                        ->label('Phòng khuyến mãi')
                        ->icon('heroicon-o-tag')
                        ->schema([
                            FileUpload::make('icon')
                                ->label('Icon / Ảnh tiêu đề')
                                ->helperText('Gắn icon flash hoặc ảnh trang trí hiển thị trước tiêu đề.')
                                ->image()
                                ->disk('public')
                                ->directory('promotion-icons')
                                ->imagePreviewHeight('60')
                                ->maxSize(4096),

                            TextInput::make('title')
                                ->label('Tiêu đề')
                                ->placeholder('VD: Ưu đãi đặc biệt')
                                ->required(),

                            Select::make('product_ids')
                                ->label('Chọn phòng khuyến mãi')
                                ->helperText('Chỉ hiển thị phòng đang có promotion còn hiệu lực.')
                                ->multiple()
                                ->searchable()
                                ->options(fn () => Product::where('is_activated', true)
                                    ->where('is_in_stock', true)
                                    ->whereHas('roomTimeSlots.promotions', fn ($q) => $q
                                        ->where('is_active', true)
                                        ->where(fn ($q2) => $q2
                                            ->whereNull('end_at')
                                            ->orWhere('end_at', '>=', now())
                                        )
                                    )
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray())
                                ->required(),
                        ]),
                ]),
        ]);
    }
}
