<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Pages;

use App\Models\Tag;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Modules\AuditLog\Services\AuditLogger;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Product\App\Filament\Resources\ProductResource;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected array $oldTagIds     = [];
    protected array $oldServiceIds = [];

    protected function getHeaderActions(): array
    {
        return [
            self::createAdditionServiceAction(),
            Actions\DeleteAction::make(),
        ];
    }

    // "Tiện ích" (field 'tags') và "Dịch vụ bổ sung" (field 'additionalServices') trên form đều là
    // Select ->relationship() — Filament tự đồng bộ pivot (model_has_tags/product_addition_service)
    // ở bước saveRelationships() riêng, KHÔNG đi qua $record->update() nên ProductObserver không
    // hề thấy được thay đổi này (đã xác nhận qua thực tế: bỏ 1 tiện ích không để lại log nào — cùng
    // lỗi với RoomAmenityAssign). beforeSave() chạy TRƯỚC bước sync đó nên chụp lại state cũ ở đây,
    // afterSave() so sánh với state mới rồi ghi log thủ công.
    protected function beforeSave(): void
    {
        $this->oldTagIds     = $this->record->tags()->pluck('tags.id')->map(fn ($id) => (string) $id)->all();
        $this->oldServiceIds = $this->record->additionalServices()->pluck('additional_services.id')->map(fn ($id) => (string) $id)->all();
    }

    protected function afterSave(): void
    {
        $record = $this->record->fresh(['tags', 'additionalServices']);

        $newTagIds     = $record->tags->pluck('id')->map(fn ($id) => (string) $id)->all();
        $addedTagIds   = array_diff($newTagIds, $this->oldTagIds);
        $removedTagIds = array_diff($this->oldTagIds, $newTagIds);

        $newServiceIds     = $record->additionalServices->pluck('id')->map(fn ($id) => (string) $id)->all();
        $addedServiceIds   = array_diff($newServiceIds, $this->oldServiceIds);
        $removedServiceIds = array_diff($this->oldServiceIds, $newServiceIds);

        if (empty($addedTagIds) && empty($removedTagIds) && empty($addedServiceIds) && empty($removedServiceIds)) {
            return;
        }

        $locale = app()->getLocale();
        $old    = [];
        $new    = [];

        if (! empty($removedTagIds)) {
            $old['tien_ich_da_bo'] = Tag::whereIn('id', $removedTagIds)->get()
                ->map(fn ($tag) => $tag->getTranslation('name', $locale))->implode(', ');
        }
        if (! empty($addedTagIds)) {
            $new['tien_ich_da_them'] = Tag::whereIn('id', $addedTagIds)->get()
                ->map(fn ($tag) => $tag->getTranslation('name', $locale))->implode(', ');
        }
        if (! empty($removedServiceIds)) {
            $old['dich_vu_da_bo'] = AdditionService::whereIn('id', $removedServiceIds)->pluck('name')->implode(', ');
        }
        if (! empty($addedServiceIds)) {
            $new['dich_vu_da_them'] = AdditionService::whereIn('id', $addedServiceIds)->pluck('name')->implode(', ');
        }

        AuditLogger::log(
            action: 'update',
            module: 'Product',
            record: $record,
            old: $old,
            new: $new,
            label: ($record->name ?? '#' . $record->id) . ' — Cập nhật tiện ích/dịch vụ bổ sung',
        );
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
