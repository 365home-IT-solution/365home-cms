<div
        class="grid gap-5 md:gap-y-4 gap-y-8 grid-cols-1
                                    @if ($smColumns == 1) sm:grid-cols-1
                                    @elseif($smColumns == 2) sm:grid-cols-2
                                    @elseif($smColumns == 3) sm:grid-cols-3
                                    @elseif($smColumns == 4) sm:grid-cols-4 @endif
                                    @if ($mdColumns == 1) md:grid-cols-1
                                    @elseif($mdColumns == 2) md:grid-cols-2
                                    @elseif($mdColumns == 3) md:grid-cols-3
                                    @elseif($mdColumns == 4) md:grid-cols-4 @endif
                                    @if ($lgColumns == 1) lg:grid-cols-1
                                    @elseif($lgColumns == 2) lg:grid-cols-2
                                    @elseif($lgColumns == 3) lg:grid-cols-3
                                    @elseif($lgColumns == 4) lg:grid-cols-4 @endif">

    @foreach ($posts as $post)
        <a href="{{ route('post.detail', ['slug' => $post->slug]) }}"
           class="overflow-hidden transition-transform duration-300 ease-in-out hover:-translate-y-1">
            <div class="relative cursor-pointer overflow-hidden text-white rounded-xl bg-clip-border mb-2">
                @if ($post->media->isNotEmpty())
                    <img src="{{ asset('/storage/' . $post->media->first()->file_path) }}"
                         alt="{{ $post->media->first()->alt_text ?? $post->title }}"
                         class="w-full h-48 object-cover rounded-xl">
                @else
                    <div class="flex justify-center items-center bg-gray-200 w-full h-48 object-cover rounded-xl">
                        <span class="text-gray-800">Bài viết không có hình ảnh</span>
                    </div>
                @endif
                <div
                        class="absolute inset-0 w-full h-full to-bg-black-10 bg-gradient-to-tr from-transparent via-transparent to-black/60">
                </div>
                {{-- Hiển thị nút "Mới" nếu bài viết được đăng trong vòng 7 ngày gần nhất --}}
                @if (now()->diffInDays($post->created_at) <= 7)
                    <!-- Thay 7 bằng số ngày bạn muốn coi là mới -->
                    <button
                            class="!absolute top-4 right-4 h-8 max-h-[32px] w-8 max-w-[32px] select-none rounded-full text-center align-middle font-sans text-xs font-medium uppercase text-red-500 transition-all hover:bg-red-500/10 active:bg-red-500/30 disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none">
                        <span class="absolute transform -translate-x-1/2 -translate-y-1/2 top-1/2 left-1/2">
                            <span style="background: {{ $primaryColor }}"
                                  class="text-white inline-block text-center px-2 py-1 rounded text-md font-semibold">Mới</span>
                        </span>
                    </button>
                @endif
            </div>
            <div class="text-gray-800 text-base font-semibold uppercase group-hover:text-primary">
                {{ $post->title }}
            </div>
            <p class="text-gray-700 mt-2">
                {{ Str::limit($post->summary, 70, '...') }}
            </p>

            <div class="flex flex-wrap justify-between items-center gap-3">
                <p class="text-xs text-gray-400 font-semibold">{{ $post->user->name }}</p>
                <div class="text-end text-sm text-gray-500">
                    <i class="fas fa-calendar-alt mr-2"></i>
                    {{ $post->created_at->format('d-m-Y') }}
                </div>
            </div>
        </a>
    @endforeach
</div>
