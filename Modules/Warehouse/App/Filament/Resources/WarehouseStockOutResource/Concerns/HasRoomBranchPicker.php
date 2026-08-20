<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Concerns;

// Ghi thẳng vào $this->data['product_id'] (mảng Filament đang bind form vào) qua wire:click chính
// thống của Livewire — cùng kỹ thuật đã dùng ổn định ở
// Payment\...\HasTimeslotGridSelection::selectTimeslot() — TRÁNH dùng Alpine/$wire.entangle tự chế
// (codebase đã ghi nhận từng gây lỗi không kích hoạt đúng afterStateUpdated).
trait HasRoomBranchPicker
{
    public function selectStockOutRoom(string $productId): void
    {
        $this->data['product_id'] = $this->data['product_id'] === $productId ? null : $productId;

        $this->dispatch('$refresh');
    }
}
