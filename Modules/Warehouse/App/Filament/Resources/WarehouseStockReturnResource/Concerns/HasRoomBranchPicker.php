<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource\Concerns;

// Y hệt Concerns\HasRoomBranchPicker của WarehouseStockOutResource — xem giải thích đầy đủ ở đó.
// Tên method GIỮ NGUYÊN "selectStockOutRoom" (không đổi thành "selectStockReturnRoom") vì view
// dùng chung 'warehouse::filament.forms.room-branch-picker' hard-code thẳng wire:click="selectStockOutRoom(...)"
// — đổi tên ở đây mà không sửa view sẽ làm nút chọn phòng im lặng không phản ứng gì.
trait HasRoomBranchPicker
{
    public function selectStockOutRoom(string $productId): void
    {
        $this->data['product_id'] = $this->data['product_id'] === $productId ? null : $productId;

        $this->dispatch('$refresh');
    }
}
