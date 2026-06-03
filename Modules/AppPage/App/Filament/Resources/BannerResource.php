<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Filament\Resources;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\AppPage\App\Filament\Resources\BannerResource\Pages;
use Modules\AppPage\App\Models\Banner;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Quản lý API';

    protected static ?string $navigationLabel = 'Banner';

    protected static ?string $modelLabel = 'Banner';

    protected static ?string $pluralModelLabel = 'Banner';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                Grid::make(2)->schema([
                    TextInput::make('title')
                        ->label('Tiêu đề')
                        ->maxLength(255),

                    TextInput::make('url')
                        ->label('URL điều hướng')
                        ->placeholder('VD: /rooms/phong-a')
                        ->maxLength(500),
                ]),

                Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),

                Grid::make(2)->schema([
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

                    Toggle::make('is_active')
                        ->label('Kích hoạt')
                        ->default(true)
                        ->inline(false),
                ]),

                FileUpload::make('image')
                    ->label('Ảnh banner')
                    ->disk(fn ($get) => $get('disk') ?? 'public')
                    ->directory('banners')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                    ->maxSize(5120)
                    ->required()
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Ảnh')
                    ->disk(fn ($record) => $record->disk ?? 'public')
                    ->height(60)
                    ->width(100),

                TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->limit(40)
                    ->placeholder('(Không có tiêu đề)'),

                TextColumn::make('url')
                    ->label('URL')
                    ->searchable()
                    ->limit(50)
                    ->placeholder('—'),

                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Kích hoạt'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit'   => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
