@foreach ($posts as $post)
    <div class="max-w-4xl w-full mx-auto bg-white rounded-xl overflow-hidden shadow-lg hover:shadow-xl transition-shadow duration-300 ease-in-out flex flex-col md:flex-row">
        <div class="w-full md:w-2/5 relative overflow-hidden h-64 md:h-auto">
            @if ($post->media->isNotEmpty())
                <img src="{{ asset('/storage/' . $post->media->first()->file_path) }}"
                     alt="{{ $post->media->first()->alt_text ?? $post->title }}"
                     class="w-full h-full object-cover rounded-t-xl md:rounded-l-xl md:rounded-t-none"/>
            @endif
        </div>
        <div class="w-full md:w-3/5 p-6 flex flex-col justify-between">
            <div>
                @if ($post->categories->isNotEmpty())
                    @foreach ($post->categories as $category)
                        @if (!empty(trim($category->name)))
                            <span class="bgColor text-white sm:text-xs md:text-xs mr-2 uppercase text-xs font-semibold px-2.5 py-1 rounded">
                                                                 {{ $category->name }}
                                                            </span>
                        @endif
                    @endforeach
                @endif
                <h2 class="sm:text-xs md:text-lg lg:text-xl font-bold text-gray-900 mt-2 mb-3 hover:text-primary-600 transition-colors duration-200">
                    {{ $post->title }}
                </h2>
                <div class="flex items-center text-gray-500 text-sm mb-4">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor"
                         viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    {{ \Carbon\Carbon::parse($post->created_at)
                        ->setTimezone('Asia/Ho_Chi_Minh')
                        ->locale('vi')
                        ->translatedFormat('d F, Y - H:i') }}
                </div>

                <p class="text-gray-600 text-sm line-clamp-2">
                    {{ Str::limit($post->summary, 100, '...') }}
                </p>
            </div>
            <div class="flex items-center flex-wrap mt-4">
                <a href="{{ route('post.detail', ['slug' => $post->slug]) }}"
                   class="relative inline-flex items-center px-8 py-2 h-[35px] overflow-hidden text-lg font-medium bgButton rounded group">
                    <span class="absolute  left-0 block w-full h-0  transition-all bgColor opacity-100 group-hover:h-full top-1/2 group-hover:top-0 duration-400 ease"></span>
                    <span class="absolute right-0 max-[375px]:hidden flex items-center justify-start w-10 h-10 duration-300 transform translate-x-full group-hover:translate-x-0 ease">
                                          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                               xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                  d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                          </svg>
                                        </span>
                    <h5 class="relative text-base font-bold uppercase max-[375px]:text-xs transition-all duration-300 group-hover:-translate-x-3">
                        Xem ngay</h5>
                </a>
            </div>
        </div>
    </div>
@endforeach