<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\Footers;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\IconPosition;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Enums\ContentTypeEnum;
use Modules\Menu\Entities\Menu;
use Modules\SettingCompany\Entities\Business;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\BaseField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\SocialMedia\FlatFormField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\SocialMedia\SocialPlatform;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\SocialMedia\UrlField;

class FooterColumnField extends BaseField
{
    protected const CONTENT_ICONS = [
        'text' => 'heroicon-o-document-text',
        'image' => 'heroicon-o-photo',
        'social_media' => 'heroicon-o-globe-alt',
        'iframe' => 'heroicon-o-code-bracket',
        'google_map' => 'heroicon-o-map',
        'menu' => 'heroicon-o-bars-3',
        'business' => 'heroicon-o-building-office-2',
    ];

    public function create(): Component
    {
        return $this->addCommonAttributes(
            Repeater::make("config.{$this->config->key}")
                ->label('Cột Footer')
                ->schema([
                    Section::make()
                        ->schema([
                            Grid::make()
                                ->schema([
                                    TextInput::make('title')
                                        ->label('Tiêu đề cột')
                                        ->maxLength(100)
                                        ->placeholder('Nhập tiêu đề cột...'),
                                ])
                                ->columns(1),

                            Repeater::make('rows')
                                ->label('Nội dung')
                                ->schema([
                                    Grid::make()
                                        ->schema([
                                            Select::make('content_type')
                                                ->label('Loại nội dung')
                                                ->required()
                                                ->options(ContentTypeEnum::labels())
                                                ->rules(['in:' . implode(',', ContentTypeEnum::values())])
                                                ->live()
                                                ->columnSpan(['lg' => 4])
                                                ->prefixIcon(fn (Get $get): string =>
                                                    self::CONTENT_ICONS[$get('content_type') ?? 'text'] ?? 'heroicon-o-document'
                                                ),

                                            $this->createContentField(),
                                        ])
                                        ->columns(12),
                                ])
                                ->itemLabel(fn (array $state): ?string =>
                                isset($state['content_type'])
                                    ? ContentTypeEnum::labels()[$state['content_type']] ?? 'Nội dung mới'
                                    : 'Nội dung mới'
                                )
                                ->collapsible()
                                ->defaultItems(1)
                                ->reorderableWithButtons()
                                ->cloneable()
                                ->deleteAction(
                                    fn ($action) => $action
                                        ->icon('heroicon-m-trash')
                                        ->iconPosition(IconPosition::After)
                                        ->size(ActionSize::Small)
                                )
                                ->reorderAction(
                                    fn ($action) => $action
                                        ->icon('heroicon-m-arrows-up-down')
                                        ->iconPosition(IconPosition::After)
                                        ->size(ActionSize::Small)
                                )
                                ->cloneAction(
                                    fn ($action) => $action
                                        ->icon('heroicon-m-square-2-stack')
                                        ->iconPosition(IconPosition::After)
                                        ->size(ActionSize::Small)
                                )
                                ->addActionLabel('Thêm nội dung')
                        ])
                        ->columns(1)
                ])
                ->itemLabel(fn (array $state): ?string =>
                    $state['title'] ?? 'Cột mới'
                )
                ->defaultItems(1)
                ->maxItems(4)
                ->reorderableWithButtons()
                ->deleteAction(
                    fn ($action) => $action
                        ->icon('heroicon-m-trash')
                        ->iconPosition(IconPosition::After)
                        ->size(ActionSize::Small)
                )
                ->reorderAction(
                    fn ($action) => $action
                        ->icon('heroicon-m-arrows-up-down')
                        ->iconPosition(IconPosition::After)
                        ->size(ActionSize::Small)
                )
                ->cloneAction(
                    fn ($action) => $action
                        ->icon('heroicon-m-square-2-stack')
                        ->iconPosition(IconPosition::After)
                        ->size(ActionSize::Small)
                )
                ->addActionLabel('Thêm cột')
        );
    }

    protected function createContentField(): Component
    {
        return Grid::make()
            ->schema([
                RichEditor::make('text_content')
                    ->label('Nội dung văn bản')
                    ->toolbarButtons([
                        'bold', 'italic', 'underline', 'strike',
                        'link', 'bulletList', 'orderedList',
                    ])
                    ->columnSpan(12)
                    ->visible(fn($get) => $get('content_type') === 'text'),

                Repeater::make('social_media')
                    ->label('Mạng xã hội')
                    ->schema([
                        Grid::make()
                            ->schema([
                                FlatFormField::create(),
                                UrlField::create()
                            ])
                            ->columns(1)
                    ])
                    ->required()
                    ->live()
                    ->defaultItems(0)
                    ->maxItems(6)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpan(12)
                    ->visible(fn($get) => $get('content_type') === 'social_media')
                    ->itemLabel(fn(array $state): ?string =>
                    isset($state['platform']) && SocialPlatform::tryFrom($state['platform'])
                        ? SocialPlatform::from($state['platform'])->label() . ': ' . ($state['url'] ?? 'Chưa có URL')
                        : 'Mạng xã hội mới'
                    )
                    ->addActionLabel('Thêm mạng xã hội')
                    ->deleteAction(
                        fn($action) => $action
                            ->button()
                            ->icon('heroicon-m-trash')
                            ->iconPosition(IconPosition::After)
                            ->size(ActionSize::Small)
                    )
                    ->reorderAction(
                        fn($action) => $action
                            ->icon('heroicon-m-arrows-up-down')
                            ->iconPosition(IconPosition::After)
                            ->size(ActionSize::Small)
                    )
                    ->cloneAction(
                        fn($action) => $action
                            ->icon('heroicon-m-square-2-stack')
                            ->iconPosition(IconPosition::After)
                            ->size(ActionSize::Small)
                    ),

                FileUpload::make('image_content')
                    ->image()
                    ->label('Hình ảnh')
                    ->columnSpan(12)
                    ->maxSize(2048)
                    ->directory('footer-images')
                    ->visible(fn($get) => $get('content_type') === 'image'),

                Textarea::make('iframe_content')
                    ->label('Mã iFrame')
                    ->rows(4)
                    ->columnSpan(12)
                    ->placeholder('<iframe src="..." width="..." height="..."></iframe>')
                    ->visible(fn($get) => $get('content_type') === 'iframe'),

                Textarea::make('google_map')
                    ->label('Mã nhúng bản đồ Google')
                    ->rows(4)
                    ->columnSpan(12)
                    ->placeholder('Dán mã nhúng bản đồ Google vào đây...')
                    ->visible(fn($get) => $get('content_type') === 'google_map'),

                Select::make('menu_id')
                    ->label('Chọn Menu')
                    ->options(fn() => Menu::all()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->columnSpan(12)
                    ->visible(fn($get) => $get('content_type') === 'menu'),

                Select::make('business_id')
                    ->label('Chọn thông tin doanh nghiệp')
                    ->options(fn() => Business::all()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->columnSpan(12)
                    ->visible(fn($get) => $get('content_type') === 'business'),
            ])
            ->columns(12)
            ->columnSpan(['lg' => 8]);
    }
}
