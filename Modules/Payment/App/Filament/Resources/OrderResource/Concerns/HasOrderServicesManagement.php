<?php

declare(strict_types=1);

namespace Modules\Payment\App\Filament\Resources\OrderResource\Concerns;

use Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm;

// Danh sách "Dịch vụ đã chọn" hiển thị bằng giao diện TỰ CHẾ (view
// 'payment::components.order-services-list'), KHÔNG dùng Repeater của Filament nữa (theo yêu
// cầu — repeater mặc định trông cồng kềnh, khó custom gọn/đẹp). Tăng/giảm số lượng + xoá dịch vụ
// gọi TRỰC TIẾP qua wire:click vào các method dưới đây, ghi thẳng vào $this->data['orderServices']
// (bỏ qua Get/Set của Filament) — CÙNG kỹ thuật đã dùng ổn định ở
// HasTimeslotGridSelection::selectTimeslot() (tránh lặp lại lỗi reactivity Alpine/$wire.entangle
// từng gặp trước đây). $key là INDEX trong mảng 'orderServices' (0,1,2...), không phải service_id
// — 1 dịch vụ có thể xuất hiện ở nhiều dòng khác nhau về mặt lý thuyết, thao tác đúng theo dòng.
trait HasOrderServicesManagement
{
    public function incrementOrderServiceQuantity(int $key): void
    {
        if (! isset($this->data['orderServices'][$key])) {
            return;
        }

        $price = (float) ($this->data['orderServices'][$key]['price'] ?? 0);
        $quantity = (int) ($this->data['orderServices'][$key]['quantity'] ?? 1) + 1;

        $this->data['orderServices'][$key]['quantity'] = $quantity;
        $this->data['orderServices'][$key]['subtotal'] = $price * $quantity;

        $this->recalculateOrderAmountAfterServicesChange();
        $this->dispatch('$refresh');
    }

    public function decrementOrderServiceQuantity(int $key): void
    {
        if (! isset($this->data['orderServices'][$key])) {
            return;
        }

        $price = (float) ($this->data['orderServices'][$key]['price'] ?? 0);
        $quantity = max(1, (int) ($this->data['orderServices'][$key]['quantity'] ?? 1) - 1);

        $this->data['orderServices'][$key]['quantity'] = $quantity;
        $this->data['orderServices'][$key]['subtotal'] = $price * $quantity;

        $this->recalculateOrderAmountAfterServicesChange();
        $this->dispatch('$refresh');
    }

    public function removeOrderService(int $key): void
    {
        if (! isset($this->data['orderServices'][$key])) {
            return;
        }

        unset($this->data['orderServices'][$key]);
        $this->data['orderServices'] = array_values($this->data['orderServices']);

        $this->recalculateOrderAmountAfterServicesChange();
        $this->dispatch('$refresh');
    }

    private function recalculateOrderAmountAfterServicesChange(): void
    {
        $this->data['amount'] = OrderForm::computeOrderTotal(
            $this->data['orderItems'] ?? [],
            $this->data['orderServices'] ?? []
        ) + (float) ($this->data['surcharge'] ?? 0);
    }
}
