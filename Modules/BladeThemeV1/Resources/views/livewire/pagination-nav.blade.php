<div x-show="$wire.lastPage > 1">
    <nav class="flex items-center justify-center gap-x-2" aria-label="Pagination" x-data="{ focusedPage: null }"
         x-init="$watch('focusedPage', value => { if (value !== null) $nextTick(() => { document.getElementById('page-' + value)?.focus() }) })">
        <button type="button" wire:click="previousPage"
                class="min-h-[38px] min-w-[38px] py-2 px-3 inline-flex justify-center items-center gap-x-1.5 text-sm font-medium rounded-md text-gray-700 bg-white border border-gray-300 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:pointer-events-none transition-all duration-200 hover:text-primary-600"
                style="--tw-ring-color: {{ $primaryColor }}; --tw-text-opacity: 1; --tw-text-opacity-hover: 0.8; --primary-color: {{ $primaryColor }};"
                @if ($currentPage <= 1) disabled @endif aria-label="Previous">
            <svg class="shrink-0 w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m15 18-6-6 6-6"></path>
            </svg>
            <span>Trước</span>
        </button>

        <div class="hidden sm:flex items-center gap-x-1">
            @php
                $visiblePages = 5;
                $halfVisible = floor($visiblePages / 2);
                $start = max(1, min($currentPage - $halfVisible, $lastPage - $visiblePages + 1));
                $end = min($lastPage, $start + $visiblePages - 1);
            @endphp

            @if ($start > 1)
                <button type="button" wire:click="setPage(1)" id="page-1"
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center py-2 px-3 text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-all duration-200 text-gray-700 hover:bg-gray-50 hover:text-primary-600 border border-gray-300"
                        style="--tw-ring-color: {{ $primaryColor }}; --tw-text-opacity: 1; --tw-text-opacity-hover: 0.8; --primary-color: {{ $primaryColor }};"
                        @focus="focusedPage = 1" wire:key="page-1">1</button>
                @if ($start > 2)
                    <span class="mx-1 text-gray-500">...</span>
                @endif
            @endif

            @for ($i = $start; $i <= $end; $i++)
                <button type="button" wire:click="setPage({{ $i }})"
                        id="page-{{ $i }}"
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center py-2 px-3 text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-all duration-200
                    {{ $i == $currentPage
                        ? 'font-semibold shadow-inner'
                        : 'text-gray-700 hover:bg-gray-50 hover:text-primary-600 border border-gray-300' }}"
                        style="
                    --tw-ring-color: {{ $primaryColor }};
                    --tw-text-opacity: 1;
                    --tw-text-opacity-hover: 0.8;
                    --primary-color: {{ $primaryColor }};
                    {{ $i == $currentPage
                        ? "background-color: rgba({$primaryColorRgb}, 0.1); color: {$primaryColor}; border: 1px solid {$primaryColor};"
                        : '' }}"
                        {{ $i == $currentPage ? 'aria-current="page"' : '' }}
                        wire:key="page-{{ $i }}-{{ $currentPage }}">
                    {{ $i }}
                </button>
            @endfor

            @if ($end < $lastPage)
                @if ($end < $lastPage - 1)
                    <span class="mx-1 text-gray-500">...</span>
                @endif
                <button type="button" wire:click="setPage({{ $lastPage }})" id="page-{{ $lastPage }}"
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center py-2 px-3 text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-all duration-200 text-gray-700 hover:bg-gray-50 hover:text-primary-600 border border-gray-300"
                        style="--tw-ring-color: {{ $primaryColor }}; --tw-text-opacity: 1; --tw-text-opacity-hover: 0.8; --primary-color: {{ $primaryColor }};"
                        @focus="focusedPage = {{ $lastPage }}"
                        wire:key="page-{{ $lastPage }}">{{ $lastPage }}</button>
            @endif
        </div>

        <button type="button" wire:click="nextPage"
                class="min-h-[38px] min-w-[38px] py-2 px-3 inline-flex justify-center items-center gap-x-1.5 text-sm font-medium rounded-md text-gray-700 bg-white border border-gray-300 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:pointer-events-none transition-all duration-200 hover:text-primary-600"
                style="--tw-ring-color: {{ $primaryColor }}; --tw-text-opacity: 1; --tw-text-opacity-hover: 0.8; --primary-color: {{ $primaryColor }};"
                @if ($currentPage >= $lastPage) disabled @endif aria-label="Next">
            <span>Kế tiếp</span>
            <svg class="shrink-0 w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"></path>
            </svg>
        </button>
    </nav>
</div>
