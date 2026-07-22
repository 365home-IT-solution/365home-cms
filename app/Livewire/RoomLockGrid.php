<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\TimeslotHold;
use App\Services\TimeslotHoldService;
use Filament\Notifications\Notification;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Payment\App\Filament\Resources\OrderResource;
use Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm;
use Modules\Product\App\Models\Product;

// Popup "Khóa tạm thời (realtime)" mở từ menu ⋮ trên thẻ phòng ở Dashboard — cho admin bấm trực
// tiếp vào lưới NGÀY × KHUNG GIỜ (dùng lại ĐÚNG view 'payment::components.timeslot-grid-table'
// và OrderForm::getTimeslotGridData() của trang tạo/sửa đơn, không dựng lưới riêng) để giữ chỗ
// real-time (TimeslotHold, tự hết hạn sau 5 phút nếu không gia hạn — xem TimeslotHoldService) mà
// KHÔNG cần tạo đơn ngay. Khi đã giữ xong, bấm "Đặt phòng" để mang các khung giờ này qua trang
// tạo đơn (CreateOrder::mount() tự đọc lại các TimeslotHold đang giữ của admin này, không cần
// truyền lại qua URL/session).
class RoomLockGrid extends Component
{
    public bool $showModal = false;

    public ?string $productId = null;
    public string $productName = '';

    public array $dates = [];
    public array $slots = [];
    public array $cells = [];

    // Khung giờ ĐANG THẬT SỰ được admin này giữ (TimeslotHold trong DB) cho đúng phòng này —
    // không phải state Repeater như OrderForm, load lại từ DB mỗi lần mở popup để luôn khớp với
    // TTL thật (nếu hold cũ đã hết hạn giữa các lần mở thì sẽ không còn xuất hiện ở đây nữa).
    public array $selectedSlots = [];

    // Dữ liệu giá/khuyến mãi cho panel bên phải lưới — build lại từ $selectedSlots + $cells mỗi
    // khi có thay đổi (xem refreshGrid()), đúng shape mà component dùng chung
    // 'payment::components.total-amount-card' (giống hệt panel giá ở trang tạo/sửa đơn) cần.
    public array $priceItems = [];

    #[On('open-room-lock-grid')]
    public function open($productId): void
    {
        $this->productId = (string) $productId;

        $product = Product::find($this->productId);
        $this->productName = $product?->name ?? '';

        $this->loadMyHeldSlots();
        $this->refreshGrid();

        $this->showModal = true;
    }

    public function close(): void
    {
        $this->showModal = false;
    }

    private function loadMyHeldSlots(): void
    {
        $this->selectedSlots = [];
        $user = auth()->user();

        if (! $user || ! $this->productId) {
            return;
        }

        $holds = TimeslotHold::whereHas('roomTimeSlot', fn ($q) => $q->where('room_id', $this->productId))
            ->where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->get();

        foreach ($holds as $hold) {
            $this->selectedSlots[] = [
                'slot_id' => $hold->room_time_slot_id,
                'date'    => $hold->date->format('Y-m-d'),
            ];
        }
    }

    private function refreshGrid(): void
    {
        if (! $this->productId) {
            $this->dates = [];
            $this->slots = [];
            $this->cells = [];

            return;
        }

        $data = OrderForm::getTimeslotGridData($this->productId);
        $this->dates = $data['dates'];
        $this->slots = $data['slots'];
        $this->cells = $data['cells'];

        $this->priceItems = $this->buildPriceItems();
    }

    // Dựng mảng "items" đúng shape mà 'payment::components.total-amount-card' cần (name, price,
    // checkin_date, checkout_date, product_id, product_style) từ các khung giờ đang giữ + giá/KM
    // đã tính sẵn trong $cells (OrderForm::getTimeslotGridData() đã áp dụng PromotionCalculator,
    // dùng lại luôn — không tính giá riêng ở đây để tránh 2 nơi ra 2 kết quả khác nhau).
    private function buildPriceItems(): array
    {
        if (! $this->productId || empty($this->selectedSlots)) {
            return [];
        }

        $product = Product::find($this->productId);

        if (! $product) {
            return [];
        }

        $items = [];

        foreach ($this->selectedSlots as $selected) {
            $slotId = (int) ($selected['slot_id'] ?? 0);
            $date   = $selected['date'] ?? null;
            $cell   = $date ? ($this->cells[$date][$slotId] ?? null) : null;

            if (! $cell) {
                continue;
            }

            $items[] = [
                'name'           => $product->name,
                'price'          => $cell['price'],
                'checkin_date'   => $cell['start']->format('Y-m-d H:i:s'),
                'checkout_date'  => $cell['end']->format('Y-m-d H:i:s'),
                'product_id'     => $this->productId,
                'product_style'  => (int) ($product->styles ?? 1),
                'guest_count'    => 1,
            ];
        }

        return $items;
    }

    // Chữ ký PHẢI khớp đúng với wire:click trong view dùng chung
    // 'payment::components.timeslot-grid-table' — $itemKey không dùng tới ở đây (không có Repeater
    // nào cả), chỉ giữ để tái sử dụng ĐÚNG view đó mà không phải sửa gì trong file dùng chung.
    public function selectTimeslot(string $itemKey, $slotId, string $date): void
    {
        $user = auth()->user();

        if (! $user || ! $this->productId) {
            return;
        }

        $slotId = (int) $slotId;
        $service = app(TimeslotHoldService::class);

        $existingIndex = null;
        foreach ($this->selectedSlots as $index => $selected) {
            if ((int) ($selected['slot_id'] ?? null) === $slotId && ($selected['date'] ?? null) === $date) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex !== null) {
            unset($this->selectedSlots[$existingIndex]);
            $this->selectedSlots = array_values($this->selectedSlots);
            $service->release($slotId, $date, $user, $this->productId);
        } else {
            $heldByOther = $service->hold($slotId, $date, $user, $this->productId);

            if ($heldByOther) {
                Notification::make()
                    ->title('Khung giờ này đang được xử lý')
                    ->body("{$heldByOther->user->fullname} đang giữ khung giờ này — vui lòng chọn khung giờ khác hoặc thử lại sau ít phút.")
                    ->warning()
                    ->send();

                $this->refreshGrid();

                return;
            }

            $this->selectedSlots[] = ['slot_id' => $slotId, 'date' => $date];
        }

        $this->refreshGrid();
    }

    // Bỏ giữ TOÀN BỘ khung giờ đang chọn của phòng này — dùng khi admin muốn huỷ hết mà không cần
    // đợi tự hết hạn.
    public function releaseAll(): void
    {
        $user = auth()->user();

        if (! $user || ! $this->productId) {
            return;
        }

        $service = app(TimeslotHoldService::class);

        foreach ($this->selectedSlots as $selected) {
            $service->release((int) $selected['slot_id'], $selected['date'], $user, $this->productId);
        }

        $this->selectedSlots = [];
        $this->refreshGrid();
    }

    // Mang các khung giờ ĐANG GIỮ sang trang tạo đơn — không truyền dữ liệu qua URL/session, vì
    // CreateOrder::mount() tự đọc lại đúng các TimeslotHold hiện có của admin này cho phòng này.
    public function goToBooking()
    {
        if (! $this->productId || empty($this->selectedSlots)) {
            Notification::make()->title('Chưa chọn khung giờ nào để đặt phòng')->warning()->send();

            return;
        }

        return $this->redirect(OrderResource::getUrl('create', ['product_id' => $this->productId]));
    }

    public function render()
    {
        return view('livewire.room-lock-grid');
    }
}
