<div class="relative" wire:ignore.self>
    <!-- Thanh tìm kiếm -->
    <div
        wire:click.away="closeSearchResults"
        class="relative max-w-md transition-all duration-300 ease-in-out hover:shadow-md rounded-xl"
    >
        <div class="flex items-center gap-3 px-4 py-2 bg-white border border-gray-200 rounded-xl overflow-hidden group hover:border-blue-400">
            <!-- Search Icon (Left) -->
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-400 group-hover:text-blue-500 transition-colors duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>

            <input
                type="text"
                wire:model.live="searchQuery"
                placeholder="Tìm kiếm bài viết..."
                class="w-full text-base text-gray-700 placeholder:text-gray-400 bg-transparent border-none focus:outline-none focus:ring-0"
            />

            <!-- Clear button (appears when input has text) -->
            <button
                class="hidden group-focus-within:block p-1 hover:bg-gray-100 rounded-full transition-colors duration-200"
                onclick="this.previousElementSibling.value = ''"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400 hover:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    <!-- Hiển thị kết quả tìm kiếm -->
    @if($isOpen && count($searchResults) > 0)
        <div class="absolute mt-2 z-10 bg-white rounded-lg shadow-xl p-4 overflow-y-auto max-h-60 w-full">
            @foreach($searchResults as $post)
                <a
                    href="{{ route('post.detail', $post->slug) }}"
                    class="flex items-center gap-4 p-2 rounded-md hover:bg-gray-100 transition-colors group"
                >
                    @if($post->media->first())
                        <img
                            src="{{ $post->media->first()->getUrl() }}"
                            alt="{{ $post->title }}"
                            class="w-16 h-12 object-cover rounded-md border border-gray-200"
                        >
                    @endif
                    <h3 class="font-medium text-sm text-gray-800 truncate max-w-[200px] group-hover:text-blue-600 transition-colors">
                        {{ $post->title }}
                    </h3>
                </a>
            @endforeach
        </div>
    @endif
</div>
