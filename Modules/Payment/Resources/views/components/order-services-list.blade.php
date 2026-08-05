{{--
    Danh sách "Dịch vụ đã chọn" — giao diện TỰ CHẾ thay cho Repeater mặc định của Filament (theo
    yêu cầu). Tăng/giảm số lượng + xoá gọi thẳng wire:click vào các method Livewire trên
    CreateOrder/EditOrder (trait HasOrderServicesManagement, ghi trực tiếp $this->data), cùng kỹ
    thuật đã dùng ổn định ở view 'timeslot-grid-table.blade.php' (selectTimeslot()) — không dùng
    Alpine/$wire.entangle tự chế, tránh lặp lại các lỗi reactivity đã gặp trước đây.
--}}
@php
    $services = is_array($services ?? null) ? $services : [];
@endphp

@if (empty($services))
    <div class="rounded-xl border border-dashed px-3 py-4 text-center"
        style="border-color: var(--boulder-80, #e5e7eb);">
        <p class="text-xs italic" style="color: var(--boulder-50, #9ca3af);">Chưa có dịch vụ nào được thêm.</p>
    </div>
@else
    <div class="space-y-2">
        @foreach ($services as $key => $service)
            @php
                $serviceId = $service['service_id'] ?? null;
                $image = $serviceId ? \Modules\BladeThemeV1\App\Models\AdditionService::find($serviceId)?->image : null;
                $name = $service['service_name'] ?: ($serviceId ? \Modules\BladeThemeV1\App\Models\AdditionService::find($serviceId)?->name : null);
                $quantity = (int) ($service['quantity'] ?? 1);
                $subtotal = (float) ($service['subtotal'] ?? 0);
            @endphp
            <div wire:key="order-service-{{ $key }}"
                class="flex items-center gap-3 rounded-xl border px-3 py-2"
                style="border-color: var(--boulder-80, #e5e7eb); background: var(--boulder-99, #fff);">

                @if ($image)
                    <img src="{{ $image }}" class="h-9 w-9 rounded-lg object-cover shrink-0" alt="" />
                @endif

                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold" style="color: var(--boulder-30, #374151);">
                        {{ $name ?: 'Dịch vụ' }}
                    </div>
                    <div class="text-xs" style="color: var(--boulder-50, #9ca3af);">
                        {{ number_format((float) ($service['price'] ?? 0), 0, ',', '.') }}đ
                    </div>
                </div>

                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" wire:click="decrementOrderServiceQuantity({{ $key }})"
                        class="flex h-6 w-6 items-center justify-center rounded-full border text-sm font-semibold"
                        style="border-color: var(--boulder-80, #e5e7eb); color: var(--boulder-30, #374151);">
                        −
                    </button>
                    <span class="w-5 text-center text-sm font-medium" style="color: var(--boulder-30, #374151);">
                        {{ $quantity }}
                    </span>
                    <button type="button" wire:click="incrementOrderServiceQuantity({{ $key }})"
                        class="flex h-6 w-6 items-center justify-center rounded-full border text-sm font-semibold"
                        style="border-color: var(--boulder-80, #e5e7eb); color: var(--boulder-30, #374151);">
                        +
                    </button>
                </div>

                <div class="w-20 shrink-0 text-right text-sm font-semibold" style="color: var(--boulder-30, #374151);">
                    {{ number_format($subtotal, 0, ',', '.') }}đ
                </div>

                <button type="button" wire:click="removeOrderService({{ $key }})"
                    title="Xoá dịch vụ"
                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-sm"
                    style="color: #dc2626;">
                    ×
                </button>
            </div>
        @endforeach
    </div>
@endif
