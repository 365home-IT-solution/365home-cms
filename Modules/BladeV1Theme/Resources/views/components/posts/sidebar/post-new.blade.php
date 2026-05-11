<div class="hidden lg:block max-w-sm mt-4 mb-4 mx-auto bg-white rounded p-4 overflow-hidden shadow-md">
    <div class="text-lg font-semibold text-gray-800">Bài viết mới</div>
    <div class="h-px bg-gray-200 w-full mb-4"></div>
    {{-- @dd($postNews[0]->getFirstMedia('Ảnh chính')) --}}
    @foreach ($postNews as $post)
        <a href="{{ route('post.detail', ['slug' => $post->slug]) }}" class="flex cursor-pointer items-start mb-4">
            <div class="w-1/3 mr-4">
                @if ($post->hasMedia('Ảnh chính'))
                    <img src="{{ $post->getFirstMedia('Ảnh chính')->getUrl() }}"
                         alt="{{ $post->getFirstMedia('Ảnh chính')->name ?? $post->title }}"
                         class="w-full h-20 object-cover rounded">
                @else
                    <div class="w-full h-20 bg-gray-50 flex items-center justify-center text-gray-700 text-xs rounded">
                        <span class="px-2">Không có hình ảnh</span>
                    </div>
                @endif
            </div>
            <div class="w-2/3">
                <div class="flex flex-wrap items-center mb-1">
                    @if ($post->categories->isNotEmpty())
                        @foreach ($post->categories as $category)
                            @if (!empty(trim($category->name)))
                                <span class="bg-primary text-white text-xs font-bold px-1 py-0.5 rounded mr-1 mb-1">
                                    {{ $category->name }}
                                </span>
                            @endif
                        @endforeach
                    @endif
                </div>
                <h3 class="text-sm font-semibold mb-1">{{ Str::limit($post->title, 40) }}</h3>
                <div class="flex items-center text-gray-600 text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span>{{ \Carbon\Carbon::parse($post->created_at)->setTimezone('Asia/Ho_Chi_Minh')->locale('vi')->translatedFormat('d F, Y') }}</span>
                </div>
            </div>
        </a>
    @endforeach
</div>
