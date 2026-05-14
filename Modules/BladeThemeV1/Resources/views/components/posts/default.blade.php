<div class="h-full">
    <div class="h-full hover-lift rounded bg-white relative group hover:shadow-xl transition-shadow duration-300 before:absolute before:inset-0 before:rounded before:transition-transform before:duration-300 group-hover:before:translate-y-[-8px] before:-z-10">
        <div class="h-full flex flex-col">
            <a href="{{ route('post.detail', ['slug' => $post->slug]) }}" class="h-full flex flex-col">
                <div class="relative rounded-t">
                    @if (now()->diffInDays($post->created_at) <= 7)
                        <div class="absolute top-2 right-2 z-10 inline-block select-none whitespace-nowrap rounded-lg bg-primary py-2 px-3.5 align-baseline font-sans text-xs font-bold uppercase leading-none text-white">
                            <div class="mt-px">Mới</div>
                        </div>
                    @endif
                    @if ($post->hasMedia('Ảnh chính'))
                        <div class="w-full h-48">
                            <img src="{{ $post->getFirstMedia('Ảnh chính')->getUrl() }}"
                                alt="{{ $post->getFirstMedia('Ảnh chính')->name ?? $post->title }}"
                                class="w-full h-full object-cover object-top rounded-t">
                        </div>
                    @else
                        <div class="flex justify-center items-center bg-gray-200 w-full h-64 rounded-t">
                            <span class="text-gray-800">Bài viết không có hình ảnh</span>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col flex-grow">
                    <figcaption class="p-4 flex-grow">
                        <p class="text-lg mb-2 mt-2 h-14 line-clamp-2 font-bold leading-relaxed text-gray-800 dark:text-gray-300">
                            {{ $post->title }}
                        </p>
                        <div class="h-10">
                            <small class="leading-5 text-gray-500 dark:text-gray-400">
                                {{ Str::limit($post->summary, 70, '...') }}
                            </small>
                        </div>
                        @if ($post->categories->count())
                            <div class="flex flex-wrap items-center gap-2 mt-2 mb-2">
                                <span class="text-sm text-black font-medium">Danh mục:</span>
                                @foreach ($post->categories as $category)
                                    @if (!empty(trim($category->name)))
                                        <span class="bg-primary text-white text-xs font-medium px-2.5 py-0.5 rounded-full">
                                            {{ $category->name }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @if ($post->tags->count() > 0)
                            <div class="mb-2">
                                <div class="inline-flex items-center">
                                    <span class="text-sm text-black font-medium mr-2">Tags:</span>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($post->tags as $tag)
                                            @if (!empty(trim($tag->name)))
                                                <span class="bg-primary text-white text-xs font-medium px-2.5 py-0.5 rounded-full">
                                                    {{ $tag->name }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                    </figcaption>
                </div>
                <div class="mt-auto">
                    <hr class="border-gray-300">
                    <div class="flex p-4 flex-wrap justify-between items-center gap-2">
                        <p class="text-xs text-gray-500 font-semibold">Tác giả: {{ $post->user->name }}</p>
                        <div class="text-end text-sm text-gray-600">
                            <i class="fas fa-calendar-alt mr-1"></i>
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
        transition: transform 0.3s ease-in-out;
    }

    .hover-lift:hover {
        transform: translateY(-0.25rem);
    }
</style>