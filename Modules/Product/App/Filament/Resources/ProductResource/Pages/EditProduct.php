<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Pages;

use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Product\App\Filament\Resources\ProductResource;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::createAdditionServiceAction(),
            Actions\DeleteAction::make(),
        ];
    }

    private static function createAdditionServiceAction(): Action
    {
        return Action::make('createAdditionService')
            ->label('Thêm dịch vụ bổ sung')
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->modalHeading('Tạo dịch vụ bổ sung mới')
            ->modalSubmitActionLabel('Tạo dịch vụ')
            ->modalCancelActionLabel('Hủy')
            ->form([
                FileUpload::make('image')
                    ->label('Ảnh dịch vụ')
                    ->image()
                    ->directory('addition-services')
                    ->imageEditor()
                    ->imageEditorAspectRatios([null, '16:9', '4:3', '1:1'])
                    ->imageCropAspectRatio('1:1')
                    ->imageResizeMode('cover')
                    ->imageResizeTargetWidth('800')
                    ->imageResizeTargetHeight('800')
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Tên dịch vụ')
                    ->required()
                    ->maxLength(255),

                TextInput::make('price')
                    ->label('Đơn giá')
                    ->numeric()
                    ->prefix('₫')
                    ->required()
                    ->minValue(0),

                Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->default(true)
                    ->inline(false),
            ])
            ->action(function (array $data) {
                AdditionService::create([
                    'name'      => $data['name'],
                    'price'     => $data['price'],
                    'image'     => $data['image'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                ]);

                \Filament\Notifications\Notification::make()
                    ->title('Đã tạo dịch vụ "' . $data['name'] . '"')
                    ->body('Dịch vụ đã sẵn sàng để chọn trong ô "Dịch vụ bổ sung" bên dưới.')
                    ->success()
                    ->send();
            })
            ->closeModalByClickingAway(true);
    }
}
