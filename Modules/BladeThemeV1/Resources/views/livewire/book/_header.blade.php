        @if(!empty($image_event))
        <div class="flex justify-center mb-4">
            <div class="w-full md:w-[380px] md:h-[120px] overflow-hidden">
                <img src="{{ asset('storage/' . $image_event) }}" alt="Sự kiện" class="w-full h-auto md:h-full md:object-cover block" loading="lazy">
            </div>
        </div>
        @endif

        <h2 class="mt-3 mb-1 text-center text-base font-bold uppercase text-primary">{{ $title_booking }}</h2>
        <h5 class="mb-3 text-center text-primary text-sm font-bold">{{ $sub_title_booking }}</h5>

        {{--
           
        <div class="flex items-center justify-center gap-3 mb-5">
            <div class="flex-1 h-px bg-gradient-to-r from-transparent to-primary/30 max-w-[120px]"></div>
            <div class="promo-badge-btn text-primary px-6 py-2 rounded-full text-md font-semibold">
                Chọn chi nhánh phù hợp với bạn
            </div>
            <div class="flex-1 h-px bg-gradient-to-l from-transparent to-primary/30 max-w-[120px]"></div>
        </div>

        <div class="overflow-x-auto mb-6 -mx-4 px-4 book-tab-scroll">
            <div class="inline-flex items-center bg-gray-800 rounded-full p-1.5 gap-1 min-w-max" id="default-styled-tab" role="tablist">
                @foreach ($categoryTabs as $category)
                <button
                        wire:click="setActiveCategoryTab({{ $category['id'] }})"
                        wire:loading.attr="disabled"
                        wire:target="setActiveCategoryTab"
                        class="inline-flex items-center gap-1.5 px-3 py-2 md:px-5 md:py-2.5 rounded-full text-xs md:text-sm font-bold tracking-wide transition-all duration-200 uppercase border-0 whitespace-nowrap {{ $activeCategoryId === $category['id'] ? 'bg-primary text-white shadow' : 'text-gray-400 hover:text-white' }}"
                        id="styled-{{ \Str::slug($category['name']) }}-tab"
                        type="button" role="tab"
                        aria-controls="styled-{{ \Str::slug($category['name']) }}"
                        aria-selected="{{ $activeCategoryId === $category['id'] ? 'true' : 'false' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 md:w-4 md:h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        {{ $category['name'] }}
                    </button>
                @endforeach
            </div>
        </div>
        <style>
            .book-tab-scroll { scrollbar-width: none; }
            .book-tab-scroll::-webkit-scrollbar { display: none; }
        </style>
        --}}
