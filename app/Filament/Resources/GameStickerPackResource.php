<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GameStickerPackResource\Pages;
use App\Filament\Resources\GameStickerPackResource\RelationManagers\StickersRelationManager;
use App\Models\GameStickerPack;
use App\Services\GameStickerService;
use App\Support\MiniGameKey;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

// Bộ sticker chat mini game (chỉ Super Admin) — cùng dữ liệu với /api/admin/games/sticker-packs, xử lý ở GameStickerService.
class GameStickerPackResource extends Resource
{
    protected static ?string $model = GameStickerPack::class;

    protected static ?string $navigationIcon   = 'heroicon-o-face-smile';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Mini game — Sticker';
    protected static ?string $modelLabel       = 'Bộ sticker';
    protected static ?string $pluralModelLabel = 'Bộ sticker mini game';
    protected static ?string $slug             = 'game-sticker-packs';
    protected static ?int    $navigationSort   = 37;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin')->schema([
                Grid::make(4)->schema([
                    TextInput::make('name')->label('Tên bộ sticker')->required()->maxLength(100)->columnSpan(2)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set, ?string $state, string $operation) => $operation === 'create' && ! $get('key') ? $set('key', Str::slug((string) $state)) : null),
                    TextInput::make('key')->label('Mã')->maxLength(50)
                        ->helperText('Không đổi được sau khi tạo.')
                        ->disabled(fn (string $operation) => $operation !== 'create')->dehydrated(fn (string $operation) => $operation === 'create'),
                    TextInput::make('sort_order')->label('Thứ tự')->numeric()->integer()->default(0),
                ]),
                Grid::make(2)->schema([
                    CheckboxList::make('games')->label('Dùng trong game')->options(MiniGameKey::LABELS)->required()->columns(2)
                        ->default(MiniGameKey::all()),
                    Toggle::make('is_active')->label('Cho khách sử dụng')->default(true),
                ]),
                FileUpload::make('icon')->label('Ảnh đại diện bộ')->image()->storeFiles(false)->maxSize(GameStickerService::MAX_KB)
                    ->helperText('Bỏ trống thì dùng sticker đầu tiên.'),
            ]),

            Section::make('Thêm sticker')->visibleOn('create')->schema([
                FileUpload::make('stickers')->label('Ảnh sticker')->multiple()->storeFiles(false)->maxSize(GameStickerService::MAX_KB)
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])->panelLayout('grid')
                    ->helperText('PNG, JPG, WebP, GIF (giữ được ảnh động), tối đa 1 MB / ảnh.'),
                FileUpload::make('stickers_zip')->label('Hoặc tải 1 file .zip')->storeFiles(false)->maxSize(102400)
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream']),
            ]),
        ]);
    }

    public static function saveFromForm(?GameStickerPack $record, array $data): GameStickerPack
    {
        $one      = fn ($value) => collect(Arr::wrap($value))->first(fn ($f) => $f instanceof UploadedFile);
        $stickers = collect(Arr::wrap($data['stickers'] ?? []))->filter(fn ($f) => $f instanceof UploadedFile)->values()->all();

        return GameStickerService::save($record, Arr::only($data, ['name', 'key', 'games', 'is_active', 'sort_order']), $stickers,
            $one($data['stickers_zip'] ?? null), $one($data['icon'] ?? null), 'data.');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('stickers'))
            ->columns([
                ImageColumn::make('samples')->label('Sticker')->stacked()->height(40)->width(40)->limit(5)
                    ->getStateUsing(fn (GameStickerPack $r) => $r->stickers->take(5)->map(fn ($s) => $s->url())->all()),
                TextColumn::make('name')->label('Bộ sticker')->description(fn (GameStickerPack $r) => $r->key)->searchable(),
                TextColumn::make('games')->label('Game')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => MiniGameKey::label($state)),
                TextColumn::make('sticker_count')->label('Số sticker')->getStateUsing(fn (GameStickerPack $r) => $r->stickers->count()),
                ToggleColumn::make('is_active')->label('Cho sử dụng'),
                TextColumn::make('sort_order')->label('Thứ tự')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('game')->label('Game')->options(MiniGameKey::LABELS)
                    ->query(fn (Builder $query, array $data) => $data['value'] ? $query->whereJsonContains('games', $data['value']) : $query),
            ])
            ->actions([
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá')
                    ->modalDescription('Xoá cả bộ và toàn bộ ảnh sticker trong bộ.')
                    ->action(function (GameStickerPack $record): void {
                        GameStickerService::delete($record);
                        Notification::make()->title('Đã xoá bộ sticker')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [StickersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListGameStickerPacks::route('/'),
            'create' => Pages\CreateGameStickerPack::route('/create'),
            'edit'   => Pages\EditGameStickerPack::route('/{record}/edit'),
        ];
    }
}
