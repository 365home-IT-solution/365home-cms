<?php

declare(strict_types=1);

namespace Modules\Payment\App\Filament\Resources\OrderResource\Concerns;

use App\Services\TimeslotHoldService;
use Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm;
use Modules\Product\App\Models\RoomTimeSlot;

// Xử lý click chọn ô trong lưới NGÀY × KHUNG GIỜ (xem OrderForm.php + view
// 'payment::components.timeslot-grid-table') — gọi TRỰC TIẾP qua wire:click (Livewire chính
// thống), ghi thẳng vào $this->data (mảng Filament đang bind form vào) thay vì đi qua
// Get/Set closures của Filament. Tránh hẳn 2 lỗi đã gặp trước đây khi thử tự chế bằng
// Alpine/$wire.entangle: (1) không kích hoạt đúng afterStateUpdated, (2) xung đột tên field
// trùng nhau giữa các Grid ẩn/hiện.
//
// CHỈ 1 bảng cho 1 phòng — chọn nhiều khung giờ nghĩa là TOGGLE nhiều phần tử trong
// 'selected_slots' của CHÍNH dòng ($itemKey) đang thao tác, KHÔNG bao giờ tạo thêm dòng/thẻ
// phòng mới trong Repeater. Việc "1 khung giờ = 1 order_item" khi lưu được xử lý riêng ở
// OrderForm::expandOrderItemsForPersistence() lúc Create/Edit submit.
trait HasTimeslotGridSelection
{
    public function selectTimeslot(string $itemKey, $slotId, string $date): void
    {
        $slot = RoomTimeSlot::with(['timeSlot', 'promotions' => fn ($q) => $q->where('is_active', true)])->find($slotId);

        if (! $slot || ! $slot->timeSlot || ! isset($this->data['orderItems'][$itemKey])) {
            return;
        }

        $slotId = (int) $slotId;
        $selectedSlots = $this->data['orderItems'][$itemKey]['selected_slots'] ?? [];

        $existingIndex = null;
        foreach ($selectedSlots as $index => $selected) {
            if ((int) ($selected['slot_id'] ?? null) === $slotId && ($selected['date'] ?? null) === $date) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex !== null) {
            // Bấm lại ô đang chọn — bỏ chọn khung giờ đó, đồng thời trả lại hold real-time để admin
            // khác thấy ô này mở lại ngay lập tức.
            unset($selectedSlots[$existingIndex]);
            $selectedSlots = array_values($selectedSlots);

            app(TimeslotHoldService::class)->release($slotId, $date, auth()->user(), (string) $slot->room_id);
        } else {
            // Bấm ô mới — giữ chỗ real-time TRƯỚC (chặn admin khác chọn trùng trong lúc đang xử lý
            // đơn này). Nếu ô đang bị admin khác giữ (chưa hết hạn) thì báo và dừng lại, KHÔNG thêm
            // vào selected_slots.
            $heldByOther = app(TimeslotHoldService::class)->hold($slotId, $date, auth()->user(), (string) $slot->room_id);

            if ($heldByOther) {
                \Filament\Notifications\Notification::make()
                    ->title('Khung giờ này đang được xử lý')
                    ->body("{$heldByOther->user->fullname} đang chọn khung giờ này cho 1 đơn khác — vui lòng chọn khung giờ khác hoặc thử lại sau ít phút.")
                    ->warning()
                    ->send();

                return;
            }

            // Bấm ô mới — thêm khung giờ này vào danh sách đã chọn của ĐÚNG dòng này.
            $selectedSlots[] = ['slot_id' => $slotId, 'date' => $date];
        }

        $this->data['orderItems'][$itemKey]['selected_slots'] = $selectedSlots;

        // checkin_date/checkout_date/price ở dòng này phản ánh khung giờ ĐẦU TIÊN đã chọn (để
        // tương thích các chỗ vẫn đọc trực tiếp field này, ví dụ hiển thị mặc định) — tổng tiền
        // và số order_item thật sự được tính/tạo dựa trên TOÀN BỘ selected_slots, xem
        // OrderForm::expandOrderItemsForPersistence() / computeOrderTotal().
        $this->recalculateItemFromSelectedSlots($itemKey, $selectedSlots);

        $this->data['amount'] = OrderForm::computeOrderTotal(
            $this->data['orderItems'] ?? [],
            $this->data['orderServices'] ?? []
        ) + (float) ($this->data['surcharge'] ?? 0);

        // Ghi thẳng vào $this->data (bỏ qua Set của Filament — xem ghi chú đầu file) khiến
        // Placeholder "Tổng thanh toán" (đọc qua $get('orderItems'), phụ thuộc cơ chế theo dõi
        // ->live() riêng của Filament) không được Filament tự đánh dấu "cần tính lại" — ép
        // Livewire render lại toàn bộ component để Placeholder đọc đúng dữ liệu mới nhất.
        $this->dispatch('$refresh');
    }

    // Gia hạn TTL (5 phút) cho MỌI khung giờ admin đang chọn dở trong đơn này — gọi định kỳ VÀ mỗi
    // khi quay lại tab (xem resources/js/echo-admin.js) MIỄN LÀ admin còn đang mở trang thao tác,
    // không cần bấm gì thêm. Không có bước này: hold tự hết hạn sau 5 phút dù admin CHƯA XONG việc
    // (vẫn đang nhập thông tin khách...) → khung giờ không còn thật sự được giữ riêng, người khác
    // có thể chọn trùng ngay trong lúc admin vẫn tưởng mình đang giữ (đã xác nhận qua thực tế).
    //
    // Trường hợp admin BỎ ĐI ĐỦ LÂU (đóng tab/mất mạng > 5 phút không quay lại) — hold đã hết hạn
    // thật và người KHÁC có thể đã chiếm mất đúng khung giờ đó trước khi admin quay lại. hold() ở
    // đây sẽ trả về khác null (bị chặn) cho trường hợp này — PHẢI xoá khỏi selected_slots + báo
    // ngay cho admin biết tại đây, KHÔNG được để admin tưởng vẫn còn giữ rồi chỉ phát hiện lúc bấm
    // "Lưu" cuối cùng (trải nghiệm tệ hơn nhiều so với báo sớm ngay khi quay lại).
    #[\Livewire\Attributes\On('renew-timeslot-holds')]
    public function renewTimeslotHolds(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $service = app(TimeslotHoldService::class);
        $lostSlotLabels = [];

        foreach ($this->data['orderItems'] ?? [] as $itemKey => $item) {
            $productId = $item['product_id'] ?? null;
            $selectedSlots = $item['selected_slots'] ?? [];

            if (! $productId || empty($selectedSlots)) {
                continue;
            }

            $remainingSlots = [];

            foreach ($selectedSlots as $selected) {
                $slotId = $selected['slot_id'] ?? null;
                $date = $selected['date'] ?? null;

                if (! $slotId || ! $date) {
                    continue;
                }

                $heldByOther = $service->hold((int) $slotId, $date, $user, (string) $productId);

                if ($heldByOther) {
                    $slot = RoomTimeSlot::with('timeSlot')->find($slotId);
                    $label = $slot?->timeSlot?->label ?? "khung giờ #{$slotId}";
                    $lostSlotLabels[] = "{$label} ngày {$date} (đã bị {$heldByOther->user->fullname} chọn cho đơn khác)";

                    continue;
                }

                $remainingSlots[] = $selected;
            }

            if (count($remainingSlots) !== count($selectedSlots)) {
                $this->data['orderItems'][$itemKey]['selected_slots'] = $remainingSlots;
                $this->recalculateItemFromSelectedSlots((string) $itemKey, $remainingSlots);
            }
        }

        if (! empty($lostSlotLabels)) {
            $this->data['amount'] = OrderForm::computeOrderTotal(
                $this->data['orderItems'] ?? [],
                $this->data['orderServices'] ?? []
            ) + (float) ($this->data['surcharge'] ?? 0);

            \Filament\Notifications\Notification::make()
                ->title('Một số khung giờ bạn chọn đã bị người khác chiếm mất')
                ->body('Do bạn rời trang quá lâu, hold đã hết hạn trước khi quay lại: ' . implode('; ', $lostSlotLabels) . '. Vui lòng chọn lại khung giờ khác.')
                ->warning()
                ->persistent()
                ->send();

            $this->dispatch('$refresh');
        }
    }

    // Tính lại checkin_date/checkout_date/price của 1 dòng order item từ danh sách selected_slots
    // MỚI NHẤT — dùng chung cho cả lúc bấm chọn (selectTimeslot()) lẫn lúc phát hiện mất khung giờ
    // do hold hết hạn (renewTimeslotHolds()), tránh lặp lại cùng 1 logic ở 2 nơi.
    private function recalculateItemFromSelectedSlots(string $itemKey, array $selectedSlots): void
    {
        if (empty($selectedSlots)) {
            $this->data['orderItems'][$itemKey]['checkin_date']  = null;
            $this->data['orderItems'][$itemKey]['checkout_date'] = null;
            $this->data['orderItems'][$itemKey]['price']         = null;
            $this->data['orderItems'][$itemKey]['discount']      = null;

            return;
        }

        $firstSelected = $selectedSlots[0];
        $firstSlot = RoomTimeSlot::with(['timeSlot', 'promotions' => fn ($q) => $q->where('is_active', true)])
            ->find($firstSelected['slot_id']);

        if ($firstSlot && $firstSlot->timeSlot) {
            [$start, $end] = OrderForm::computeSlotDatetimes($firstSlot, $firstSelected['date']);
            $promo = app(\App\Services\PromotionCalculator::class)->calculate($firstSlot, $firstSelected['date']);

            $this->data['orderItems'][$itemKey]['checkin_date']  = $start->format('Y-m-d H:i:s');
            $this->data['orderItems'][$itemKey]['checkout_date'] = $end->format('Y-m-d H:i:s');
            $this->data['orderItems'][$itemKey]['price']         = (int) $promo['final_price'];
            $this->data['orderItems'][$itemKey]['discount']      = (int) $firstSlot->price;
        }
    }
}
