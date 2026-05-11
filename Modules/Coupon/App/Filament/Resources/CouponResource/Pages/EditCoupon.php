<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Pages;

use Modules\Coupon\App\Filament\Resources\CouponResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCoupon extends EditRecord
{
    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Uppercase code
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        // Nếu không phải specific_slot, xóa room_time_slot_ids
        if (($data['apply_type'] ?? null) !== 'specific_slot') {
            unset($data['room_time_slot_ids']);
        }

        // Nếu không phải specific_room hoặc specific_slot, xóa room_id
        if (!in_array($data['apply_type'] ?? null, ['specific_room', 'specific_slot'])) {
            $data['room_id'] = null;
        }

        return $data;
    }
}