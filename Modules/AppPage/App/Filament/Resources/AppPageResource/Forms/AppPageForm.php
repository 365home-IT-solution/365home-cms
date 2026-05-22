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
                            Select::make('disk')
                                ->label('Storage disk')
                                ->options([
                                    'public' => 'Public (local)',
                                    's3'     => 'Amazon S3',
                                    'r2'     => 'Cloudflare R2',
                                ])
                                ->default('public')
                                ->required()
                                ->live(),

                            Repeater::make('items')
                                ->label('Danh sách banner')
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextInput::make('title')
                                            ->label('Tiêu đề')
                                            ->maxLength(255),

                                        TextInput::make('url')
                                            ->label('URL điều hướng')
                                            ->placeholder('VD: /rooms/phong-a')
                                            ->maxLength(500),
                                    ]),

                                    TextInput::make('description')
                                        ->label('Mô tả')
                                        ->maxLength(500)
                                        ->columnSpanFull(),

                                    FileUpload::make('image')
                                        ->label('Ảnh banner')
                                        ->disk(fn (Get $get) => $get('../../disk') ?? 'public')
                                        ->directory('banners')
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                                        ->maxSize(5120)
                                        ->columnSpanFull()
                                        ->required(),
                                ])
                                ->grid(3)
                                ->addActionLabel('+ Thêm banner')
                                ->collapsible()
                                ->collapsed()
                                ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Banner chưa đặt tiêu đề')
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

                            Select::make('product_ids')
                                ->label('Chọn phòng')
                                ->multiple()
                                ->searchable()
                                ->options(fn () => Product::where('is_activated', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray())
                                ->placeholder('Tìm theo tên phòng...')
                                ->required(),
                        ]),
                ]),
        ]);
    }
}
