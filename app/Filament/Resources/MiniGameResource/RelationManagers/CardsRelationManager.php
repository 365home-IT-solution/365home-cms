<?php

declare(strict_types=1);

namespace App\Filament\Resources\MiniGameResource\RelationManagers;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

// Các lá bài của 1 mini game — mỗi lá chỉ là 1 tấm ảnh. Lá mới / lá vừa bật có mặt từ lần trộn kế tiếp của khách; ván đang
// chơi dở giữ bộ bài cũ.
class CardsRelationManager extends RelationManager
{
    protected static string $relationship = 'cards';

    protected static ?string $title = 'Lá bài';

    protected static ?string $modelLabel = 'lá bài';

    // Form sửa 1 lá (đổi ảnh, bật/tắt). Thêm lá thì dùng action "Thêm lá bài" ở table() để upload nhiều ảnh một lượt.
    public function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('image')
                ->label('Ảnh lá bài (mặt trước)')
                ->image()
                ->disk('public')
                ->directory('mini-games/cards')
                ->maxSize(5120)
                ->required()
                ->columnSpanFull(),

            Toggle::make('is_active')
                ->label('Đang bật')
                ->helperText('Tắt thì lá này không được chia ở các lần trộn sau.')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#'),

                ImageColumn::make('image')
                    ->label('Ảnh')
                    ->disk('public')
                    ->height(120),

                ToggleColumn::make('is_active')
                    ->label('Đang bật'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                Action::make('addCards')
                    ->label('Thêm lá bài')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Thêm lá bài')
                    ->modalSubmitActionLabel('Thêm')
                    ->form([
                        FileUpload::make('images')
                            ->label('Ảnh lá bài (mặt trước)')
                            ->helperText('Chọn một hoặc nhiều ảnh, mỗi ảnh là một lá. Kéo thả để sắp xếp thứ tự trước khi thêm.')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->panelLayout('grid')
                            ->disk('public')
                            ->directory('mini-games/cards')
                            ->maxSize(5120)
                            ->maxFiles(50)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $cards = $this->getOwnerRecord()->cards();
                        $order = (int) $cards->max('sort_order');

                        foreach ($data['images'] as $image) {
                            $this->getOwnerRecord()->cards()->create(['image' => $image, 'sort_order' => ++$order]);
                        }

                        Notification::make()
                            ->title('Đã thêm ' . count($data['images']) . ' lá bài')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Xoá các lá đã chọn'),
                ]),
            ]);
    }
}
