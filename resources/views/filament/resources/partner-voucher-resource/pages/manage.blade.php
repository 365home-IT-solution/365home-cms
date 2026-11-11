<x-filament-panels::page>
    {{-- Không dùng <form>: nút Lưu cần hỏi xác nhận khi bỏ tick mọi loại voucher (Enter trong ô nhập sẽ bỏ qua bước đó). --}}
    <div class="grid gap-y-6">
        {{ $this->form }}

        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" />
    </div>
</x-filament-panels::page>
