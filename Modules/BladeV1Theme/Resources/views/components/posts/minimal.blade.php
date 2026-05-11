<div class="bg-white rounded-xl overflow-hidden shadow-lg hover:shadow-2xl transition-all duration-300 hover-lift">
    <a href="{{ route('post.detail', ['slug' => $post->slug]) }}" class="block">
        <div class="p-6">
            <h2 class="text-2xl font-bold text-gray-800 transition-colors duration-300 mb-3">
                {{ $post->title }}
            </h2>

            @if ($post->categories->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 mb-2">
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


            <p class="text-gray-600 mb-4">
                {{ Str::limit($post->summary, 120, '...') }}
            </p>

            @if ($post->tags->isNotEmpty())
                <div class="mb-4">
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

            <hr class="my-4 border-gray-200">

            <div class="flex justify-between items-center text-sm text-gray-500">
                <span class="flex items-center">
                    <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20"
                         xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                              clip-rule="evenodd"/>
                    </svg>
                    {{ $post->user->name }}
                </span>
                <span class="flex items-center">
                    <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20"
                         xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                              d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z"
                              clip-rule="evenodd"/>
                    </svg>
                    {{ $post->created_at->format('d-m-Y') }}
                </span>
            </div>
        </div>
    </a>
</div>

<style>
    .hover-lift {
        transition: transform 0.3s ease-in-out;
    }

    .hover-lift:hover {
        transform: translateY(-0.25rem);
    }
</style>