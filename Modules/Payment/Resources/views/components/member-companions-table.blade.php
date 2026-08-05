{{--
    Danh sách "Người đi cùng" (thành viên) dạng bảng — giao diện TỰ CHẾ thay cho CheckboxList/
    Repeater cũ (theo yêu cầu — cần bảng gọn, chỉ tên + số CCCD, kèm action Sửa/Xoá). Hiện TẤT CẢ
    người đi cùng đã có sẵn trong hồ sơ thành viên (không chỉ người đã gắn vào đơn này) để admin
    chọn nhanh lại, không phải tải ảnh quét lại mỗi lần đặt đơn mới cho cùng 1 thành viên — người
    ĐÃ chọn cho đơn này được đánh dấu riêng. Sửa/Xoá/Thêm gọi thẳng wire:click vào trait
    HasMemberCompanionManagement (ghi trực tiếp $this->data), cùng kỹ thuật đã dùng ổn định ở
    order-services-list.blade.php.
--}}
@php
    $companions = $companions ?? collect();
    $selectedIds = $selectedIds ?? [];
    $selectedCount = count($selectedIds);
    $atMax = $maxCompanions > 0 && $selectedCount >= $maxCompanions;
@endphp

<div class="space-y-2">
    <div class="overflow-hidden rounded-xl border" style="border-color: var(--boulder-80, #e5e7eb);">
        <table class="w-full text-left" style="font-size: 0.8125rem;">
            <thead style="background: var(--boulder-95, #f9fafb); border-bottom: 1px solid var(--boulder-80, #e5e7eb);">
                <tr>
                    <th class="px-3 py-2 text-xs font-semibold uppercase" style="color: var(--boulder-50, #6b7280);">Tên</th>
                    <th class="px-3 py-2 text-xs font-semibold uppercase" style="color: var(--boulder-50, #6b7280);">Số CCCD</th>
                    <th class="px-3 py-2 text-xs font-semibold uppercase text-right" style="color: var(--boulder-50, #6b7280);">Hành động</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($companions as $c)
                    @php
                        $data = $c->cccd_data ?? [];
                        // So sánh KHÔNG strict — $c->id là int (khoá chính customer_companions),
                        // trong khi $selectedIds luôn là string (tham số Livewire từ wire:click,
                        // xem HasMemberCompanionManagement) — strict in_array() sẽ luôn false dù
                        // cùng 1 companion, khiến badge "Đã thêm" không bao giờ hiện đúng.
                        $isSelected = in_array($c->id, $selectedIds);
                    @endphp
                    <tr wire:key="member-companion-{{ $c->id }}" style="border-top: 1px solid var(--boulder-90, #f3f4f6);">
                        <td class="px-3 py-2 font-medium" style="color: var(--boulder-30, #374151);">
                            {{ $c->full_name ?: '(chưa rõ tên)' }}
                            @if ($isSelected)
                                <span style="display:inline-block;margin-left:6px;font-size:11px;font-weight:600;color:#15803d;background:#dcfce7;border-radius:9999px;padding:1px 8px;">Đã thêm</span>
                            @endif
                        </td>
                        <td class="px-3 py-2" style="color: var(--boulder-50, #6b7280);">
                            {{ $data['cccd'] ?? 'chưa có số CCCD' }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button type="button" wire:click="showEditMemberCompanionPanel('{{ $c->id }}')"
                                title="Sửa ảnh CCCD"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-full"
                                style="color: var(--boulder-50, #6b7280); font-size: 1rem;">
                                ✎
                            </button>
                            @if ($isSelected)
                                <button type="button" wire:click="removeMemberCompanion('{{ $c->id }}')"
                                    title="Xoá khỏi đơn"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-full"
                                    style="color: #dc2626; font-size: 1.25rem;">
                                    ×
                                </button>
                            @else
                                <button type="button" wire:click="addExistingMemberCompanion('{{ $c->id }}')"
                                    title="Thêm vào đơn"
                                    @if ($atMax) disabled @endif
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-full"
                                    style="color: {{ $atMax ? 'var(--boulder-80, #d1d5db)' : '#15803d' }}; font-size: 1.25rem;">
                                    +
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-3 py-4 text-center italic" style="color: var(--boulder-50, #9ca3af);">
                            Thành viên chưa có người đi cùng nào trong hồ sơ.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($atMax)
        <p class="text-xs italic" style="color: var(--boulder-50, #9ca3af);">
            Đã đạt tối đa {{ $maxCompanions }} người đi cùng cho đơn này.
        </p>
    @else
        <button type="button" wire:click="showAddMemberCompanionPanel()"
            class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed px-3 py-2 text-sm font-medium"
            style="border-color: var(--boulder-80, #e5e7eb); color: var(--boulder-50, #6b7280);">
            + Thêm người đi cùng mới
        </button>
    @endif
</div>
