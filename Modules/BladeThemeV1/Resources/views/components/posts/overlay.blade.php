<div class="relative bg-white h-80 group rounded-lg overflow-hidden shadow-[0_2px_10px_-3px_rgba(6,81,237,0.3)]">
    <a href="{{ route('post.detail', ['slug' => $post->slug]) }}" class="block h-full">
        @if ($post->hasMedia('Ảnh chính'))
            <img src="{{ $post->getFirstMedia('Ảnh chính')->getUrl() }}"
                 alt="{{ $post->getFirstMedia('Ảnh chính')->name ?? $post->title }}"
                 class="w-full object-cover transition-transform duration-300 group-hover:scale-105">
        @else
            <div class="flex justify-center items-center bg-gray-200 w-full h-80">
                <span class="text-gray-800">Bài viết không có hình ảnh</span>
            </div>
        @endif
        @if (now()->diffInDays($post->created_at) <= 7)
            <div class="absolute top-2 right-2 z-10 inline-block select-none whitespace-nowrap rounded-lg bg-primary py-2 px-3.5 align-baseline font-sans text-xs font-bold uppercase leading-none text-white">
                <div class="mt-px">Mới</div>
            </div>
        @endif
        <div class="p-6 absolute bg-primary bottom-0 left-0 right-0 opacity-90 transition-opacity duration-300 group-hover:opacity-100">
            <div class="flex flex-wrap justify-between items-center gap-2">
                <p class="text-sm text-white font-semibold">Tác giả: {{ $post->user->name }}</p>
                <div class="text-end text-sm text-white">
                    <i class="fas fa-calendar-alt mr-1"></i>
                    {{ $post?->created_at->format('d-m-Y') }}
                </div>
            </div>
            <h3 class="post-title uppercase text-lg font-semibold text-white mt-3 leading-tight group-hover:underline">
                {{ $post->title }}
            </h3>
            @if ($post->categories->count())
                <div class="flex flex-wrap items-center gap-2 mt-2">
                    @foreach ($post->categories as $category)
                        @if (!empty(trim($category->name)))
                            <span class="bg-white text-primary text-xs font-medium px-2.5 py-0.5 rounded-full">
                                {{ $category->name }}
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif
            <div class="h-0 overflow-hidden group-hover:h-16 group-hover:mt-4 transition-all duration-300 ease-in-out">
                <p class="text-sm post-summary text-white">
                    {{ Str::limit($post->summary, 90, '...') }}
                </p>
            </div>
        </div>
    </a>
</div>
