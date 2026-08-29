<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Pages;

use Modules\Coupon\App\Filament\Resources\CouponResource;
use Filament\Resources\Pages\CreateRecord;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // Field 'room_ids'/'room_time_slot_ids' là Select ->relationship() — pivot được Filament tự
    // đồng bộ ở saveRelationships(), sau khi record đã tồn tại. Ghi log 1 dòng tóm tắt phòng/khung
    // giờ được gán ngay từ lúc tạo, cùng nguyên tắc với EditCoupon::afterSave().
    protected function afterCreate(): void
    {
        $record = $this->record->fresh(['rooms', 'roomTimeSlots']);

        $roomIds     = $record->rooms->pluck('id')->all();
        $roomSlotIds = $record->roomTimeSlots->pluck('id')->all();

        if (empty($roomIds) && empty($roomSlotIds)) {
            return;
        }

        $new = [];

        if (! empty($roomIds)) {
            $new['phong_da_them'] = Product::whereIn('id', $roomIds)->pluck('name')->implode(', ');
        }
        if (! empty($roomSlotIds)) {
            $new['khung_gio_da_them'] = RoomTimeSlot::whereIn('id', $roomSlotIds)->with(['room', 'timeSlot'])->get()
                ->map(function (RoomTimeSlot $slot) {
                    $roomName  = $slot->room?->name ?? ('#' . $slot->room_id);
                    $slotLabel = $slot->timeSlot?->label ?? ('#' . $slot->timeslot_id);

                    return "{$roomName} - {$slotLabel}";
                })
                ->implode(', ');
        }

        AuditLogger::log(
            action: 'update',
            module: 'Coupon',
            record: $record,
            old: [],
            new: $new,
            label: ($record->name ?? $record->code ?? '#' . $record->id) . ' — Gán phòng/khung giờ áp dụng',
        );
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