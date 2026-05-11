<div class="p-2">
    <div class="h-full hover-lift rounded-xl bg-white relative group hover:shadow-2xl shadow-lg transition-all duration-500 overflow-hidden">
        <div class="h-full flex flex-col">
            <a href="{{ route('post.detail', ['slug' => $post->slug]) }}" class="h-full flex flex-col">
                <div class="relative rounded-t-xl overflow-hidden">
                    @if (now()->diffInDays($post->created_at) <= 7)
                        <div class="absolute top-3 right-3 z-10 inline-block select-none whitespace-nowrap rounded-full bg-primary py-2 px-4 align-baseline font-sans text-xs font-bold uppercase leading-none text-white shadow-lg">
                            <div class="mt-px">Mới</div>
                        </div>
                    @endif
                    @if ($post->hasMedia('Ảnh chính'))
                        <div class="w-full h-56 relative">
                            <img src="{{ $post->getFirstMedia('Ảnh chính')->getUrl() }}"
                                 alt="{{ $post->getFirstMedia('Ảnh chính')->name ?? $post->title }}"
                                 class="w-full h-full object-cover object-center rounded-t-xl transition-transform duration-700 ease-out group-hover:scale-110">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                        </div>
                    @else
                        <div class="flex justify-center items-center bg-gradient-to-br from-gray-100 to-gray-200 w-full h-56 rounded-t-xl">
                            <div class="text-center">
                                <i class="fas fa-image text-4xl text-gray-400 mb-2"></i>
                                <span class="text-gray-500 text-sm">Bài viết không có hình ảnh</span>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col flex-grow bg-white">
                    <figcaption class="p-6 flex-grow">


                        <h3 class="group-hover:text-primary group-hover:bg-clip-text transition-all duration-500 ease-out text-xl mb-3 font-bold leading-tight text-gray-900 line-clamp-2">
                            {{ $post->title }}
                        </h3>

                        <div class="mb-4">
                            <p class="leading-relaxed text-gray-600 text-sm line-clamp-2">
                                {{ Str::limit($post->summary, 100, '...') }}
                            </p>
                        </div>

                        @if ($post->categories->count())
                            <div class="flex flex-wrap items-center gap-2 mb-3">
                                <span class="text-sm text-gray-700 font-medium">Danh mục:</span>
                                @foreach ($post->categories as $category)
                                    @if (!empty(trim($category->name)))
                                        <span class="bg-primary text-white text-xs font-medium px-3 py-1 rounded-full shadow-sm">
                                            {{ $category->name }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        @if ($post->tags->count() > 0)
                            <div class="mb-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm text-gray-700 font-medium">Tags:</span>
                                    @foreach ($post->tags as $tag)
                                        @if (!empty(trim($tag->name)))
                                            <span class="bg-primary text-white text-xs font-medium px-3 py-1 rounded-full shadow-sm">
                                                {{ $tag->name }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </figcaption>
                </div>

                <div class="mt-auto bg-gray-50 border-t border-gray-100">
                    <div class="flex p-4 flex-wrap justify-between items-center gap-2">
                        <p class="text-xs text-gray-600 font-semibold flex items-center">
                            <i class="fas fa-user-circle mr-2 text-gray-500"></i>
                            {{ $post->user?->name }}
                        </p>
                        <div class="text-end text-sm text-gray-500 flex items-center">
                            <i class="fas fa-calendar-alt mr-2"></i>
                            {{ $post?->created_at->format('d-m-Y') }}
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>

<style scoped>
    .hover-lift {
        transition: all 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    .hover-lift:hover {
        transform: translateY(-4px) scale(1.01);
        z-index: 10;
        position: relative;
    }

    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* Gradient text effect */
    .group:hover .group-hover\:bg-clip-text {
        background-clip: text;
        -webkit-background-clip: text;
    }

    /* Smooth shadow transition */
    .hover-lift {
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    .hover-lift:hover {
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.05);
    }
</style>