<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Pages;

use Modules\Coupon\App\Filament\Resources\CouponResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        if (($data['apply_type'] ?? null) !== 'specific_slot') {
            unset($data['room_time_slot_ids']);
        }

        if (! in_array($data['apply_type'] ?? null, ['specific_room', 'specific_slot'])) {
            $data['room_id'] = null;
        }

        $data['created_by'] = auth()->id();

        return $data;
    }
}