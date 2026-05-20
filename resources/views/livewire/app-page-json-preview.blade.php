<div wire:key="json-preview">
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">

        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
            <div class="flex items-center gap-2">
                <div class="flex gap-1">
                    <span class="h-2.5 w-2.5 rounded-full bg-red-400"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-yellow-400"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-green-400"></span>
                </div>
                <span class="text-xs font-mono text-gray-500 dark:text-gray-400">GET /api/pages/{{ $page?->slug ?? '{slug}' }}</span>
            </div>
            <span class="rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-medium text-green-700 dark:bg-green-900 dark:text-green-300">200 OK</span>
        </div>

        {{-- JSON body --}}
        <div class="overflow-auto" style="max-height: calc(100vh - 220px);">
            @if ($json)
                <pre class="p-4 text-[11px] leading-relaxed font-mono text-gray-800 dark:text-gray-200 whitespace-pre-wrap break-words">{{ $json }}</pre>
            @else
                <div class="flex flex-col items-center justify-center py-16 text-center text-gray-400">
                    <svg class="mb-3 h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <p class="text-xs font-medium">Chưa có dữ liệu</p>
                    <p class="mt-1 text-[10px]">Thêm block rồi lưu để xem JSON</p>
                </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="border-t border-gray-200 px-4 py-2 dark:border-gray-700">
            <p class="text-[10px] text-gray-400">Cập nhật sau mỗi lần lưu trang</p>
        </div>
    </div>
</div>
