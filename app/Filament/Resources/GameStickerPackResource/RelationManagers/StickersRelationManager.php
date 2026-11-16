<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameStickerPackResource\RelationManagers;

use App\Models\GameSticker;
use App\Models\GameStickerPack;
use App\Services\GameStickerService;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

// Sticker trong bộ: thêm (nhiều ảnh / zip), kéo thả sắp xếp, xoá — đều qua GameStickerService để version của bộ đổi theo.
class StickersRelationManager extends RelationManager
{
    protected static string $relationship = 'stickers';

    protected static ?string $title = 'Sticker';

    protected static ?string $modelLabel = 'sticker';

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated([50, 100, 'all'])
            ->columns([
                ImageColumn::make('image')->label('Ảnh')->getStateUsing(fn (GameSticker $r) => $r->url())->height(56)->width(56),
                TextColumn::make('id')->label('Mã'),
            ])
            ->headerActions([
                Action::make('add')->label('Thêm sticker')->icon('heroicon-o-plus')
                    ->form([
                        FileUpload::make('stickers')->label('Ảnh sticker')->multiple()->storeFiles(false)->maxSize(GameStickerService::MAX_KB)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])->panelLayout('grid')
                            ->helperText('PNG, JPG, WebP, GIF (giữ được ảnh động), tối đa 1 MB / ảnh.'),
                        FileUpload::make('stickers_zip')->label('Hoặc tải 1 file .zip')->storeFiles(false)->maxSize(102400)
                            ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream']),
                    ])
                    ->action(function (array $data): void {
                        /** @var GameStickerPack $pack */
                        $pack  = $this->getOwnerRecord();
                        $files = collect(Arr::wrap($data['stickers'] ?? []))->filter(fn ($f) => $f instanceof UploadedFile)->values()->all();
                        $zip   = collect(Arr::wrap($data['stickers_zip'] ?? null))->first(fn ($f) => $f instanceof UploadedFile);
                        $before = $pack->stickers()->count();

                        GameStickerService::save($pack, [], $files, $zip, null, 'mountedTableActionsData.0.');
                        Notification::make()->title('Đã thêm ' . ($pack->stickers()->count() - $before) . ' sticker')->success()->send();
                    }),
            ])
            ->actions([
                DeleteAction::make()->label('Xoá')
                    ->action(fn (GameSticker $record) => GameStickerService::deleteSticker($record)),
            ])
            ->bulkActions([]);
    }

    public function reorderTable(array $order): void
    {
        /** @var GameStickerPack $pack */
        $pack = $this->getOwnerRecord();
        GameStickerService::reorder($pack, $order);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
