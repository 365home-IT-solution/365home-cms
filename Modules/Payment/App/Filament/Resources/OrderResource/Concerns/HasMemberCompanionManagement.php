<?php

declare(strict_types=1);

namespace Modules\Payment\App\Filament\Resources\OrderResource\Concerns;

// Danh sách "Người đi cùng" (thành viên) trong popup "CCCD thành viên" — hiển thị dạng bảng TỰ CHẾ
// (view 'payment::components.member-companions-table'), KHÔNG dùng CheckboxList/Repeater của
// Filament nữa (theo yêu cầu — cần bảng gọn có action Sửa/Xoá thay vì dropdown chọn nhiều mục).
// Sửa/Xoá/Thêm gọi TRỰC TIẾP qua wire:click vào các method dưới đây, ghi thẳng vào
// $this->data['member_companion_ids']/['member_companion_panel_id'] (bỏ qua Get/Set của Filament)
// — CÙNG kỹ thuật đã dùng ổn định ở HasOrderServicesManagement/HasTimeslotGridSelection (tránh lặp
// lại lỗi reactivity Alpine/$wire.entangle từng gặp). 'member_companion_ids' KHÔNG phải field thật
// trong ->form() của popup — chỉ là mảng companion_id đang "chọn cho đơn này", được
// OrderForm::buildMemberCccdAction() đọc thẳng qua $livewire khi bấm "Lưu" của popup (xem
// OrderForm::persistMemberCompanionsToOrder()).
trait HasMemberCompanionManagement
{
    public function showAddMemberCompanionPanel(): void
    {
        $this->data['member_companion_panel_id'] = 'new';
        $this->dispatch('$refresh');
    }

    public function showEditMemberCompanionPanel(string $companionId): void
    {
        $this->data['member_companion_panel_id'] = $companionId;
        $this->dispatch('$refresh');
    }

    public function hideMemberCompanionPanel(): void
    {
        $this->data['member_companion_panel_id'] = null;
        $this->dispatch('$refresh');
    }

    public function removeMemberCompanion(string $companionId): void
    {
        $ids = $this->data['member_companion_ids'] ?? [];
        $this->data['member_companion_ids'] = array_values(array_diff($ids, [$companionId]));
        $this->dispatch('$refresh');
    }

    // Chọn nhanh 1 người đi cùng ĐÃ CÓ SẴN trong hồ sơ thành viên (không cần tải ảnh lại) — thêm
    // thẳng companion_id vào danh sách của đơn này. Tính lại 'max_companions' tại đây theo CÙNG
    // công thức resolveMaxCompanionsForMember() (guest_count - 1) từ 'orderItems.0.guest_count'
    // sống trong $this->data, vì trait này chạy trên trang (không có sẵn $record ở CreateOrder khi
    // đơn chưa lưu, nên không gọi được thẳng OrderForm::resolveMaxCompanionsForMember($record)).
    public function addExistingMemberCompanion(string $companionId): void
    {
        // So sánh KHÔNG strict — companion_id có thể đến từ 2 nguồn kiểu khác nhau (int từ DB lúc
        // seed ban đầu ở fillForm(), string từ tham số wire:click ở đây) — xem ghi chú tương tự ở
        // member-companions-table.blade.php.
        $ids = $this->data['member_companion_ids'] ?? [];
        if (in_array($companionId, $ids)) {
            return;
        }

        $maxCompanions = max(0, (int) data_get($this->data, 'orderItems.0.guest_count', 1) - 1);
        if ($maxCompanions > 0 && count($ids) >= $maxCompanions) {
            \Filament\Notifications\Notification::make()
                ->title('Đã đạt tối đa ' . $maxCompanions . ' người đi cùng')
                ->warning()
                ->send();

            return;
        }

        $ids[] = $companionId;
        $this->data['member_companion_ids'] = $ids;
        $this->dispatch('$refresh');
    }
}
