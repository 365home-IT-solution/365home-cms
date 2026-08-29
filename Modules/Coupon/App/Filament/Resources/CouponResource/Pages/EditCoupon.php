<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Pages;

use Modules\Coupon\App\Filament\Resources\CouponResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

class EditCoupon extends EditRecord
{
    protected static string $resource = CouponResource::class;

    protected array $oldRoomIds     = [];
    protected array $oldRoomSlotIds = [];

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

    // Field 'room_ids' (relationship 'rooms') và 'room_time_slot_ids' (relationship 'roomTimeSlots')
    // đều là Select ->relationship() nhiều-nhiều — Filament tự đồng bộ pivot ở bước
    // saveRelationships() riêng, KHÔNG đi qua $record->update() nên không có Eloquent event nào
    // bắn ra để ghi log (cùng lỗi đã gặp ở Product tags/services). beforeSave() chạy TRƯỚC bước
    // đó nên chụp lại state cũ ở đây, afterSave() so sánh với state mới rồi ghi log thủ công.
    protected function beforeSave(): void
    {
        $this->oldRoomIds     = $this->record->rooms()->pluck('products.id')->map(fn ($id) => (string) $id)->all();
        $this->oldRoomSlotIds = $this->record->roomTimeSlots()->pluck('room_time_slots.id')->map(fn ($id) => (string) $id)->all();
    }

    protected function afterSave(): void
    {
        $record = $this->record->fresh(['rooms', 'roomTimeSlots']);

        $newRoomIds     = $record->rooms->pluck('id')->map(fn ($id) => (string) $id)->all();
        $addedRoomIds   = array_diff($newRoomIds, $this->oldRoomIds);
        $removedRoomIds = array_diff($this->oldRoomIds, $newRoomIds);

        $newRoomSlotIds     = $record->roomTimeSlots->pluck('id')->map(fn ($id) => (string) $id)->all();
        $addedRoomSlotIds   = array_diff($newRoomSlotIds, $this->oldRoomSlotIds);
        $removedRoomSlotIds = array_diff($this->oldRoomSlotIds, $newRoomSlotIds);

        if (empty($addedRoomIds) && empty($removedRoomIds) && empty($addedRoomSlotIds) && empty($removedRoomSlotIds)) {
            return;
        }

        $old = [];
        $new = [];

        if (! empty($removedRoomIds)) {
            $old['phong_da_bo'] = Product::whereIn('id', $removedRoomIds)->pluck('name')->implode(', ');
        }
        if (! empty($addedRoomIds)) {
            $new['phong_da_them'] = Product::whereIn('id', $addedRoomIds)->pluck('name')->implode(', ');
        }
        if (! empty($removedRoomSlotIds)) {
            $old['khung_gio_da_bo'] = self::roomTimeSlotLabels($removedRoomSlotIds);
        }
        if (! empty($addedRoomSlotIds)) {
            $new['khung_gio_da_them'] = self::roomTimeSlotLabels($addedRoomSlotIds);
        }

        AuditLogger::log(
            action: 'update',
            module: 'Coupon',
            record: $record,
            old: $old,
            new: $new,
            label: ($record->name ?? $record->code ?? '#' . $record->id) . ' — Cập nhật phòng/khung giờ áp dụng',
        );
    }

    private static function roomTimeSlotLabels(array $ids): string
    {
        return RoomTimeSlot::whereIn('id', $ids)->with(['room', 'timeSlot'])->get()
            ->map(function (RoomTimeSlot $slot) {
                $roomName  = $slot->room?->name ?? ('#' . $slot->room_id);
                $slotLabel = $slot->timeSlot?->label ?? ('#' . $slot->timeslot_id);

                return "{$roomName} - {$slotLabel}";
            })
            ->implode(', ');
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