<div class="p-2">
    <div class="h-full rounded-xl bg-white relative group shadow-sm overflow-hidden">
        <a href="{{ route('post.detail', ['slug' => $post->slug]) }}" class="h-full flex flex-col">
            <div class="relative rounded-t-xl overflow-hidden aspect-video w-full bg-gray-100">
                @if ($post->hasMedia('Ảnh chính'))
                    <img src="{{ $post->getFirstMedia('Ảnh chính')->getUrl() }}"
                         alt="{{ $post->title }}"
                         class="w-full h-full object-cover object-center">
                @else
                    <div class="flex justify-center items-center bg-gradient-to-br from-gray-100 to-gray-200 w-full h-full">
                        <div class="text-center">
                            <i class="fas fa-image text-2xl text-gray-400 mb-1"></i>
                            <span class="text-gray-500 text-xs block">Bài viết không có hình ảnh</span>
                        </div>
                    </div>
                @endif
            </div>

            <div class="p-4 flex-grow flex flex-col bg-white">
                <h3 class="text-primary group-hover:opacity-80 transition-opacity duration-300 ease-out text-base mb-2 font-bold leading-snug line-clamp-2">
                    {{ $post->title }}
                </h3>

                <p class="leading-relaxed text-gray-600 text-sm line-clamp-2">
                    {{ Str::limit($post->summary, 90, '...') }}
                </p>

                <time datetime="{{ $post->created_at->toIso8601String() }}" class="mt-2 block text-xs text-gray-400">
                    {{ $post->created_at->format('d-m-Y') }}
                </time>
            </div>
        </a>
    </div>
</div>

<style scoped>
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
</style>
