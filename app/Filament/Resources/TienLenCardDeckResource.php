<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TienLenCardDeckResource\Pages;
use App\Games\TienLen\Card;
use App\Models\TienLenCardDeck;
use App\Models\TienLenSetting;
use App\Services\TienLenDeckService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Bộ bài Tiến lên (chỉ Super Admin) — cùng dữ liệu với /api/admin/games/tien-len/card-decks, xử lý ở TienLenDeckService.
class TienLenCardDeckResource extends Resource
{
    protected static ?string $model = TienLenCardDeck::class;

    protected static ?string $navigationIcon   = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Tiến lên — Bộ bài';
    protected static ?string $modelLabel       = 'Bộ bài';
    protected static ?string $pluralModelLabel = 'Bộ bài Tiến lên';
    protected static ?string $slug             = 'game-tien-len-card-decks';
    protected static ?int    $navigationSort   = 35;

    // Không có mục riêng ở sidebar — mở từ nút "Bộ bài" trên trang Tiến lên — Ván chơi
    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin')->schema([
                Grid::make(4)->schema([
                    TextInput::make('name')->label('Tên bộ bài')->required()->maxLength(100)->columnSpan(2)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set, ?string $state, string $operation) => $operation === 'create' && ! $get('key') ? $set('key', Str::slug((string) $state)) : null),
                    TextInput::make('key')->label('Mã')->maxLength(50)
                        ->helperText('Không đổi được sau khi tạo.')
                        ->disabled(fn (string $operation) => $operation !== 'create')->dehydrated(fn (string $operation) => $operation === 'create'),
                    TextInput::make('sort_order')->label('Thứ tự')->numeric()->integer()->default(0),
                ]),
                Toggle::make('is_active')->label('Cho khách sử dụng')->default(true),
            ]),

            Section::make('Ảnh')->schema([
                Grid::make(2)->schema([
                    FileUpload::make('back')->label('Mặt lưng')->image()->storeFiles(false)->maxSize(10240)
                        ->required(fn (string $operation) => $operation === 'create'),
                    FileUpload::make('preview')->label('Ảnh tổng quan')->image()->storeFiles(false)->maxSize(10240),
                ]),
                FileUpload::make('cards')->label('Ảnh các lá')->multiple()->image()->storeFiles(false)->maxFiles(52)->maxSize(10240)
                    ->helperText('Tên file là mã lá: AS.png, 10H.png, hoặc Bich_A.png, Tep_10.png, Ro_K.png, Co_2.png. Chỉ lá được tải lên mới bị thay.')
                    ->panelLayout('grid'),
                FileUpload::make('cards_zip')->label('Hoặc tải 1 file .zip')->storeFiles(false)->maxSize(12288)
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])
                    ->helperText('Đặt tên file trong zip như trên; back.png / Mat_lung.png là mặt lưng, preview.png / Tong_quan.jpg là ảnh tổng quan.'),
            ]),

            Section::make('Ảnh hiện tại')->hiddenOn('create')->schema([
                Placeholder::make('current')->hiddenLabel()->content(fn (?TienLenCardDeck $record) => $record ? self::cardGrid($record) : null),
            ]),
        ]);
    }

    // Lưới 52 lá + mặt lưng; lá thiếu hiện khung trống.
    private static function cardGrid(TienLenCardDeck $d): HtmlString
    {
        $imgs  = $d->card_images ?? [];
        $tile  = fn (?string $url, string $label) => '<div style="text-align:center;font-size:11px">'
            . ($url ? '<img src="' . e($url) . '" alt="' . e($label) . '" style="width:56px;height:78px;border-radius:4px;object-fit:cover">'
                : '<div style="width:56px;height:78px;border-radius:4px;border:1px dashed rgb(156 163 175)"></div>')
            . '<div>' . e($label) . '</div></div>';
        $html  = $tile(TienLenCardDeck::url($d->back_image), 'Lưng');
        foreach (Card::deck() as $id) {
            $code  = Card::code($id);
            $html .= $tile(TienLenCardDeck::url($imgs[$code] ?? null), Card::label($id));
        }
        $missing = $d->missingCodes();
        $note    = $missing ? '<p style="margin-top:8px;font-size:13px;color:rgb(217 119 6)">Thiếu ' . count($missing) . ' lá (' . e(implode(', ', $missing)) . ') — tạm dùng ảnh bộ mặc định.</p>' : '';

        return new HtmlString('<div style="display:flex;flex-wrap:wrap;gap:6px">' . $html . '</div>' . $note);
    }

    /**
     * Gọi service lưu bộ bài từ dữ liệu form (dùng ở trang tạo / sửa).
     */
    public static function saveFromForm(?TienLenCardDeck $record, array $data): TienLenCardDeck
    {
        $one   = fn (string $k) => collect(Arr::wrap($data[$k] ?? null))->first(fn ($f) => $f instanceof UploadedFile);
        $cards = collect(Arr::wrap($data['cards'] ?? []))->filter(fn ($f) => $f instanceof UploadedFile)->values()->all();

        return TienLenDeckService::save($record, Arr::only($data, ['name', 'key', 'is_active', 'sort_order']), $cards, $one('cards_zip'), $one('back'), $one('preview'), 'data.');
    }

    public static function table(Table $table): Table
    {
        $default = fn () => TienLenSetting::current()->default_card_deck;

        return $table
            ->columns([
                ImageColumn::make('back_image')->label('Lưng')->getStateUsing(fn (TienLenCardDeck $r) => TienLenCardDeck::url($r->back_image))->height(56)->width(40),
                ImageColumn::make('samples')->label('Mẫu')->stacked()->height(56)->width(40)->limit(4)
                    ->getStateUsing(fn (TienLenCardDeck $r) => collect(['AS', 'KH', 'QD', '2C'])->map(fn ($c) => TienLenCardDeck::url($r->card_images[$c] ?? null))->filter()->values()->all()),
                TextColumn::make('name')->label('Bộ bài')->description(fn (TienLenCardDeck $r) => $r->key)->searchable(),
                TextColumn::make('card_count')->label('Số lá')->getStateUsing(fn (TienLenCardDeck $r) => $r->cardCount() . '/52')
                    ->badge()->color(fn (TienLenCardDeck $r) => $r->cardCount() === 52 ? 'success' : 'warning')
                    ->tooltip(fn (TienLenCardDeck $r) => $r->missingCodes() ? 'Thiếu: ' . implode(', ', $r->missingCodes()) : null),
                TextColumn::make('is_default')->label('Mặc định')->getStateUsing(fn (TienLenCardDeck $r) => $r->key === $default() ? 'Mặc định' : null)
                    ->badge()->color('primary')->placeholder('—'),
                ToggleColumn::make('is_active')->label('Cho sử dụng')
                    ->disabled(fn (TienLenCardDeck $r) => $r->key === $default())
                    ->updateStateUsing(function (TienLenCardDeck $record, bool $state) {
                        try {
                            TienLenDeckService::setActive($record, $state);
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }

                        return $record->fresh()->is_active;
                    }),
                TextColumn::make('sort_order')->label('Thứ tự')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Action::make('makeDefault')->label('Đặt mặc định')->icon('heroicon-o-star')->color('gray')
                    ->visible(fn (TienLenCardDeck $r) => $r->is_active && $r->key !== $default())
                    ->action(function (TienLenCardDeck $record): void {
                        TienLenDeckService::makeDefault($record);
                        Notification::make()->title("Đã đặt {$record->name} làm bộ mặc định")->success()->send();
                    }),
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá')
                    ->modalDescription('Khách đang dùng bộ này sẽ chuyển về bộ mặc định. Ảnh đã tải lên bị xoá.')
                    ->hidden(fn (TienLenCardDeck $r) => $r->key === $default())
                    ->action(function (TienLenCardDeck $record): void {
                        try {
                            TienLenDeckService::delete($record);
                            Notification::make()->title('Đã xoá bộ bài')->success()->send();
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
            'index'  => Pages\ListTienLenCardDecks::route('/'),
            'create' => Pages\CreateTienLenCardDeck::route('/create'),
            'edit'   => Pages\EditTienLenCardDeck::route('/{record}/edit'),
        ];
    }
}
