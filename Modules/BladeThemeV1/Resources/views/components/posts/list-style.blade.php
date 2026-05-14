@foreach ($posts as $post)
    <div
        class="w-full mx-auto bg-white rounded-xl overflow-hidden shadow-lg hover:shadow-xl transition-shadow duration-300 ease-in-out flex flex-col md:flex-row">
        <div class="w-full md:w-[350px] relative overflow-hidden h-[300px]">
            @if ($post->hasMedia('Ảnh chính'))
                <img src="{{ $post->getFirstMedia('Ảnh chính')->getUrl() }}"
                    alt="{{ $post->getFirstMedia('Ảnh chính')->name ?? $post->title }}"
                    class="w-full h-full object-cover rounded-t-xl md:rounded-l-xl md:rounded-t-none" />
            @else
                <div
                    class="w-full h-full rounded-t-xl md:rounded-l-xl md:rounded-t-none flex justify-center items-center bg-gray-50">
                    <span>Không có hình ảnh</span>
                </div>
            @endif
        </div>
        <div class="w-full md:w-3/5 p-6 flex flex-col justify-between">
            <div>
                <div class="mt-2 flex flex-wrap gap-2">
                    @if ($post->categories->isNotEmpty())
                        @foreach ($post->categories as $category)
                            @if (!empty(trim($category->name)))
                                <span
                                    class="bg-primary text-white sm:text-xs md:text-xs mr-2 uppercase text-xs font-semibold px-2.5 py-1 rounded">
                                    {{ $category->name }}
                                </span>
                            @endif
                        @endforeach
                    @endif
                </div>

                <a href="{{ route('post.detail', ['slug' => $post->slug]) }}"s
                    class="hover:text-primary sm:text-xs md:text-lg lg:text-xl font-bold text-gray-900 mt-2 mb-3 hover:text-primary-600 transition-colors duration-200">
                    {{ $post->title }}
                </a>
                <div class="flex items-center text-gray-500 text-sm mb-4">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z">
                        </path>
                    </svg>
                    {{ \Carbon\Carbon::parse($post->created_at)->setTimezone('Asia/Ho_Chi_Minh')->locale('vi')->translatedFormat('d F, Y - H:i') }}
                </div>

                <p class="text-gray-600 text-sm line-clamp-2">
                    {{ Str::limit($post->summary, 100, '...') }}
                </p>
            </div>

            @if ($post->tags->isNotEmpty())
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($post->tags as $tag)
                        @if (!empty(trim($tag->name)))
                            <span
                                class="font-sans text-xs font-bold uppercase leading-none text-white px-2 py-1 rounded {{ !empty(trim($tag->name)) ? 'bg-primary' : '' }}">
                                {{ $tag->name }}
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif
            <div class="flex items-center flex-wrap mt-4">
                <x-bladethemev1::buttons.button :text_size="'md:text-md text-sm'" :style="'1'"
                    href="{{ route('post.detail', ['slug' => $post->slug]) }}">
                    <span>Xem ngay</span>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                    </svg>
                </x-bladethemev1::buttons.button>
            </div>
        </div>
    </div>
@endforeach
