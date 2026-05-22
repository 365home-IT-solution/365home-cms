<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomImageResource\Forms;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Modules\Product\App\Models\Product;

class RoomImageForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(4)->schema([

                // ── Cột 1 (1/3): thông tin ────────────────────────────────
                Group::make([
                    Select::make('room_id')
                        ->label('Phòng')
                        ->options(Product::where('is_activated', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Select::make('type')
                        ->label('Loại ảnh')
                        ->options([
                            'main'    => 'Ảnh chính',
                            'gallery' => 'Ảnh phụ (Gallery)',
                        ])
                        ->default('gallery')
                        ->required()
                        ->live(),

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

                    TextInput::make('alt')
                        ->label('Alt text (SEO)')
                        ->placeholder('Mô tả ngắn về ảnh để tối ưu SEO')
                        ->maxLength(255),

                    TextInput::make('title')
                        ->label('Title (SEO)')
                        ->placeholder('Tiêu đề ảnh')
                        ->maxLength(255),

                    TextInput::make('sort_order')
                        ->label('Thứ tự')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),
                ])->columnSpan(1),

                // ── Cột 2 (2/3): ảnh ──────────────────────────────────────
                Group::make([
                    // Ảnh chính — single upload
                    FileUpload::make('path')
                        ->label('Ảnh chính')
                        ->acceptedFileTypes(['image/avif', 'image/webp', 'image/jpeg', 'image/png', 'image/gif'])
                        ->disk(fn (Get $get): string => $get('disk') ?? 'public')
                        ->directory('room-images')
                        ->multiple()
                        ->maxFiles(1)
                        ->imageEditor()
                        ->panelLayout('grid')
                        ->downloadable()
                        ->maxSize(5120)
                        ->visible(fn (Get $get): bool => $get('type') === 'main')
                        ->dehydrated(fn (Get $get): bool => $get('type') === 'main')
                        ->required(fn (Get $get): bool => $get('type') === 'main'),

                    // Gallery — repeater theo khu vực, nội dung 2 cột
                    Repeater::make('gallery_path')
                        ->label('Khu vực ảnh')
                        ->schema([
                            TextInput::make('title')
                                ->label('Tiêu đề khu vực')
                                ->placeholder('VD: Phòng khách, Phòng ngủ...')
                                ->maxLength(100),

                            RichEditor::make('description')
                                ->label('Mô tả')
                                ->toolbarButtons([
                                    'bold', 'italic', 'bulletList', 'orderedList', 'undo', 'redo',
                                ]),

                            FileUpload::make('images')
                                ->label('Ảnh')
                                ->acceptedFileTypes(['image/avif', 'image/webp', 'image/jpeg', 'image/png', 'image/gif'])
                                ->disk(fn (Get $get) => $get('/disk') ?? 'public')
                                ->directory('room-images')
                                ->multiple()
                                ->panelLayout('grid')
                                ->imageEditor()
                                ->reorderable()
                                ->appendFiles()
                                ->downloadable()
                                ->maxSize(5120),
                        ])
                        ->grid(3)
                        ->addActionLabel('+ Thêm khu vực')
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Khu vực chưa đặt tên')
                        ->visible(fn (Get $get): bool => $get('type') === 'gallery')
                        ->required(fn (Get $get): bool => $get('type') === 'gallery'),
                ])->columnSpan(3),

            ])->columnSpanFull(),
        ]);
    }
}
