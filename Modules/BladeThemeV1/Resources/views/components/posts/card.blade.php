<div class="p-2">
    <div class="post-card group">
        <a href="{{ route('post.detail', ['slug' => $post->slug]) }}" class="h-full flex flex-col">
            <div class="post-card-thumb">
                @if ($post->hasMedia('Ảnh chính'))
                    <img src="{{ $post->getFirstMedia('Ảnh chính')->getUrl() }}"
                         alt="{{ $post->title }}"
                         class="post-card-img">
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
                <h3 class="post-card-title">
                    {{ $post->title }}
                </h3>

                <p class="post-card-summary">
                    {{ Str::limit($post->summary, 90, '...') }}
                </p>

                <time datetime="{{ $post->created_at->toIso8601String() }}" class="mt-2 block text-xs text-gray-400">
                    {{ $post->created_at->format('d-m-Y') }}
                </time>
            </div>
        </a>
    </div>
</div>
