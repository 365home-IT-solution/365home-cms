<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Pages;

use Modules\Payment\App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Modules\BladeThemeV1\Services\AccessCode\AccessCodeService;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record->fresh(['items.product']);

        if ($record->status !== 'paid') {
            return;
        }

        if ($record->hasAccessCode()) {
            return;
        }

        try {
            $firstItem    = $record->items->sortBy('checkin_date')->first();
            $checkinDate  = $record->items->min('checkin_date');
            $checkoutDate = $record->items->max('checkout_date');
            $product      = $firstItem?->product;

            /** @var AccessCodeService $service */
            $service = app(AccessCodeService::class);
            $code = $service->assignCodeToOrder(
                $record->id,
                $record->category_id,
                $checkinDate,
                $checkoutDate,
                $product,
            );

            Notification::make()
                ->title('Đã gán mã cổng: ' . $code->code)
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Tạo đơn thành công nhưng chưa gán được mã cổng')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }
}