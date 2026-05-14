@php
$productTagsCollection = collect($productTags);
$totalTags = $productTagsCollection->count();
$visibleTags = $productTagsCollection->take(6);
$groupedTags = $productTagsCollection->groupBy(fn($tag) => $tag->tag_type ?: 'Khác');
@endphp

<div class="mb-8" x-data="{ showAmenities: false }"
    x-init="$watch('showAmenities', v => $dispatch(v ? 'amenities-modal-open' : 'amenities-modal-close'))">
    <h3 class="text-3xl font-bold my-8">Tiện nghi phòng</h3>

    {{-- Grid hiển thị tối đa 6 items (3 hàng × 2 cột) --}}
    <div class="grid grid-cols-2 gap-8">
        @forelse ($visibleTags as $tag)
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 shrink-0">
                @if (!empty($tag->tag_image))
                <img src="{{ asset('storage/'.$tag->tag_image) }}"
                             class="w-full h-full" alt="{{ $tag->tag_name }}">
                    @else
                <div class="w-full h-full bg-gray-200 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z" />
                    </svg>
                </div>
                @endif
            </div>
            <p class="font-medium text-xl">{{ $tag->tag_name }}</p>
        </div>
        @empty
        <p>Không có tiện nghi nào được liệt kê.</p>
        @endforelse
    </div>

    {{-- Nút "Hiển thị tất cả" (chỉ khi > 6 tiện nghi) --}}
    @if ($totalTags > 6)
    <button type="button"
                @click="showAmenities = true"
                class="mt-6 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl border border-gray-900 text-gray-900 font-semibold text-sm hover:bg-gray-100 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
            Hiển thị tất cả {{ $totalTags }} tiện nghi
        </button>
    @endif

    {{-- Popup Modal — teleport to body để thoát khỏi stacking context của parent --}}
    <template x-teleport="body">
        <div x-show="showAmenities" x-cloak x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[99999] flex items-end sm:items-center justify-center sm:p-4"
            style="background:rgba(0,0,0,0.5);"
            x-init="$watch('showAmenities', v => { document.body.style.overflow = v ? 'hidden' : ''; })"
            @click.self="showAmenities = false" @keydown.escape.window="showAmenities = false">

            <div x-show="showAmenities" x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-full sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-full sm:translate-y-0 sm:scale-95"
                class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full sm:max-w-lg flex flex-col overflow-hidden"
                style="max-height:85vh; max-height:85dvh;">

                {{-- Drag handle (mobile only) --}}
                <div class="flex justify-center pt-3 pb-1 sm:hidden shrink-0">
                    <div class="w-10 h-1 rounded-full bg-gray-300"></div>
                </div>

                {{-- Header --}}
                <div
                    class="flex items-center justify-between px-5 py-3 sm:px-6 sm:py-4 border-b border-gray-100 shrink-0">
                    <h2 class="text-lg sm:text-xl font-bold text-gray-900">Nơi này có những gì cho bạn</h2>
                    <button type="button"
                            @click="showAmenities = false"
                            class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {{-- Body (scrollable) --}}
                <div class="overflow-y-auto flex-1 px-5 py-4 sm:px-6 space-y-5"
                    style="-webkit-overflow-scrolling: touch;">
                    @foreach ($groupedTags as $type => $tags)
                    <div>
                        @if ($type && $type !== 'Khác')
                        <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wide mb-2">{{ $type }}</h3>
                        @endif
                        <div class="divide-y divide-gray-100">
                            @foreach ($tags as $tag)
                            <div class="flex items-center gap-3 py-3">
                                <div class="w-6 h-6 sm:w-7 sm:h-7 shrink-0">
                                    @if (!empty($tag->tag_image))
                                    <img src="{{ asset('storage/'.$tag->tag_image) }}"
                                                     class="w-full h-full object-contain" alt="{{ $tag->tag_name }}">
                                            @else
                                    <div class="w-full h-full bg-gray-100 rounded flex items-center justify-center">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z" />
                                        </svg>
                                    </div>
                                    @endif
                                </div>
                                <span class="text-gray-700 text-sm font-medium">{{ $tag->tag_name }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </template>
</div>