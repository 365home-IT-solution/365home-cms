<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GameSoundPackResource\Pages;
use App\Models\GameSoundPack;
use App\Services\GameSoundPackService;
use App\Support\MiniGameKey;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Bộ âm thanh mini game Tiến lên / Caro (chỉ Super Admin) — cùng dữ liệu với /api/admin/games/sound-packs, xử lý ở GameSoundPackService.
class GameSoundPackResource extends Resource
{
    protected static ?string $model = GameSoundPack::class;

    protected static ?string $navigationIcon   = 'heroicon-o-musical-note';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Mini game — Âm thanh';
    protected static ?string $modelLabel       = 'Bộ âm thanh';
    protected static ?string $pluralModelLabel = 'Bộ âm thanh mini game';
    protected static ?string $slug             = 'game-sound-packs';
    protected static ?int    $navigationSort   = 36;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin')->schema([
                Grid::make(4)->schema([
                    Select::make('game')->label('Game')->options(MiniGameKey::LABELS)->required()->live()
                        ->disabled(fn (string $operation) => $operation !== 'create')->dehydrated(fn (string $operation) => $operation === 'create'),
                    TextInput::make('name')->label('Tên bộ âm thanh')->required()->maxLength(100)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set, ?string $state, string $operation) => $operation === 'create' && ! $get('key') ? $set('key', Str::slug((string) $state)) : null),
                    TextInput::make('key')->label('Mã')->maxLength(50)
                        ->helperText('Không đổi được sau khi tạo.')
                        ->disabled(fn (string $operation) => $operation !== 'create')->dehydrated(fn (string $operation) => $operation === 'create'),
                    TextInput::make('sort_order')->label('Thứ tự')->numeric()->integer()->default(0),
                ]),
                Grid::make(2)->schema([
                    Toggle::make('is_active')->label('Cho khách sử dụng')->default(true),
                    FileUpload::make('cover')->label('Ảnh bìa')->image()->storeFiles(false)->maxSize(5120),
                ]),
            ]),

            Section::make('Tải âm thanh')
                ->description('MP3, M4A, AAC, OGG, WAV, tối đa 10 MB.')
                ->visible(fn (Get $get) => filled($get('game')))
                ->schema(fn (Get $get) => collect(GameSoundPackService::events((string) $get('game')))->map(fn (array $e, string $event) =>
                    FileUpload::make("sounds.{$event}")->label($e['label'])->storeFiles(false)
                        ->maxSize(GameSoundPackService::isMusic($event) ? GameSoundPackService::MAX_MUSIC_KB : GameSoundPackService::MAX_KB)
                )->values()->all()),

            Section::make('Âm thanh hiện tại')->hiddenOn('create')->schema([
                Placeholder::make('current')->hiddenLabel()->content(fn (?GameSoundPack $record) => $record ? self::soundList($record) : null),
                CheckboxList::make('remove_sounds')->label('Xoá file')->columns(4)
                    ->options(fn (?GameSoundPack $record) => $record ? collect(GameSoundPackService::events($record->game))
                        ->filter(fn (array $_, string $event) => ! empty($record->sounds[$event] ?? null))->map(fn (array $e) => $e['label'])->all() : [])
                    ->visible(fn (?GameSoundPack $record) => $record && ! empty($record->sounds)),
            ]),
        ]);
    }

    // Danh sách sự kiện + trình phát file đang có; sự kiện chưa có file ghi rõ sẽ dùng bộ mặc định.
    private static function soundList(GameSoundPack $p): HtmlString
    {
        $rows = '';
        foreach (GameSoundPackService::events($p->game) as $event => $e) {
            $url   = GameSoundPack::url($p->sounds[$event] ?? null);
            $cell  = $url ? '<audio controls preload="none" src="' . e($url) . '" style="height:32px;max-width:100%"></audio>'
                : '<span style="color:rgb(156 163 175)">' . ($p->is_default ? 'Chưa có — app dùng âm thanh có sẵn' : 'Chưa có — dùng bộ mặc định') . '</span>';
            $rows .= '<tr><td style="padding:4px 12px 4px 0;white-space:nowrap">' . e($e['label']) . '</td><td style="padding:4px 0">' . $cell . '</td></tr>';
        }

        return new HtmlString('<table style="font-size:14px">' . $rows . '</table>');
    }

    public static function saveFromForm(?GameSoundPack $record, array $data): GameSoundPack
    {
        $one    = fn ($value) => collect(Arr::wrap($value))->first(fn ($f) => $f instanceof UploadedFile);
        $sounds = collect($data['sounds'] ?? [])->map($one)->filter()->all();

        return GameSoundPackService::save($record, Arr::only($data, ['game', 'name', 'key', 'is_active', 'sort_order']), $sounds,
            $one($data['sounds_zip'] ?? null), $one($data['cover'] ?? null), (array) ($data['remove_sounds'] ?? []), 'data.');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('game')->label('Game')->formatStateUsing(fn (string $state) => MiniGameKey::label($state))->badge()->color('gray'),
                TextColumn::make('name')->label('Bộ âm thanh')->description(fn (GameSoundPack $r) => $r->key)->searchable(),
                TextColumn::make('sound_count')->label('Số âm thanh')
                    ->getStateUsing(fn (GameSoundPack $r) => (count(GameSoundPackService::events($r->game)) - count(GameSoundPackService::missingEvents($r))) . '/' . count(GameSoundPackService::events($r->game)))
                    ->badge()->color(fn (GameSoundPack $r) => GameSoundPackService::missingEvents($r) ? 'warning' : 'success'),
                TextColumn::make('is_default')->label('Mặc định')->getStateUsing(fn (GameSoundPack $r) => $r->is_default ? 'Mặc định' : null)
                    ->badge()->color('primary')->placeholder('—'),
                ToggleColumn::make('is_active')->label('Cho sử dụng')
                    ->disabled(fn (GameSoundPack $r) => $r->is_default)
                    ->updateStateUsing(function (GameSoundPack $record, bool $state) {
                        try {
                            GameSoundPackService::setActive($record, $state);
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }

                        return $record->fresh()->is_active;
                    }),
                TextColumn::make('sort_order')->label('Thứ tự')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('game')->label('Game')->options(MiniGameKey::LABELS),
            ])
            ->actions([
                Action::make('makeDefault')->label('Đặt mặc định')->icon('heroicon-o-star')->color('gray')
                    ->visible(fn (GameSoundPack $r) => $r->is_active && ! $r->is_default)
                    ->action(function (GameSoundPack $record): void {
                        GameSoundPackService::makeDefault($record);
                        Notification::make()->title("Đã đặt {$record->name} làm bộ mặc định của " . MiniGameKey::label($record->game))->success()->send();
                    }),
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá')
                    ->modalDescription('Khách đang dùng bộ này sẽ chuyển về bộ mặc định. File đã tải lên bị xoá.')
                    ->hidden(fn (GameSoundPack $r) => $r->is_default)
                    ->action(function (GameSoundPack $record): void {
                        try {
                            GameSoundPackService::delete($record);
                            Notification::make()->title('Đã xoá bộ âm thanh')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListGameSoundPacks::route('/'),
            'create' => Pages\CreateGameSoundPack::route('/create'),
            'edit'   => Pages\EditGameSoundPack::route('/{record}/edit'),
        ];
    }
}
