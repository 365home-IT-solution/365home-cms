<x-filament-panels::page>
    {{-- Nút "Tô đen khung giờ" + modal tích hợp --}}
    <livewire:book::block-timeslot-modal />

    {{-- Lọc theo chi nhánh + phân trang — trước đây trang này build form cho TOÀN BỘ phòng cùng
         lúc (mỗi phòng 1 Tab + TableRepeater khung giờ/khuyến mãi), đối tác nhiều phòng làm server
         tốn RAM rất lớn. Giờ chỉ build {{ $this->perPage }} phòng/trang, dùng link GET thường
         (không phải wire:click) để tải lại trang với query string mới — tránh phải giữ toàn bộ
         state phân trang qua Livewire. --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-white/5">
        <form method="GET" class="flex items-center gap-2">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Chi nhánh:</label>
            <select name="branch_id" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                <option value="">Tất cả chi nhánh</option>
                @foreach($this->branchOptions() as $branchOptId => $branchOptName)
                    <option value="{{ $branchOptId }}" @selected($this->branchId === (string) $branchOptId)>{{ $branchOptName }}</option>
                @endforeach
            </select>
        </form>

        <div class="flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
            <span>
                Trang {{ $this->paginatorMeta['current_page'] }}/{{ $this->paginatorMeta['last_page'] }}
                &middot; {{ $this->paginatorMeta['total'] }} phòng
            </span>
            @php
                $bookQueryBase = request()->except('page');
                $bookPrevPage  = max(1, $this->paginatorMeta['current_page'] - 1);
                $bookNextPage  = min($this->paginatorMeta['last_page'], $this->paginatorMeta['current_page'] + 1);
            @endphp
            <a href="?{{ http_build_query(array_merge($bookQueryBase, ['page' => $bookPrevPage])) }}"
               @class([
                   'rounded-lg border px-3 py-1.5 font-medium',
                   'pointer-events-none opacity-40' => $this->paginatorMeta['current_page'] <= 1,
                   'border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-white/5' => $this->paginatorMeta['current_page'] > 1,
               ])>&lsaquo; Trước</a>
            <a href="?{{ http_build_query(array_merge($bookQueryBase, ['page' => $bookNextPage])) }}"
               @class([
                   'rounded-lg border px-3 py-1.5 font-medium',
                   'pointer-events-none opacity-40' => $this->paginatorMeta['current_page'] >= $this->paginatorMeta['last_page'],
                   'border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-white/5' => $this->paginatorMeta['current_page'] < $this->paginatorMeta['last_page'],
               ])>Sau &rsaquo;</a>
        </div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end gap-3">
            <x-filament::button type="submit" color="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Lưu cài đặt</span>
                <span wire:loading wire:target="save" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    Đang lưu...
                </span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>