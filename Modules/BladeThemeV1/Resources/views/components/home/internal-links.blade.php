{{-- Bài viết mới + chi nhánh/khu vực — link thật trong HTML server để crawler đi từ trang chủ vào
     trang con (xem Modules\BladeThemeV1\Support\HomeInternalLinks). View này có trong
     tailwind.home.config.js — thêm class mới thì build lại home.css. --}}
@php($links = \Modules\BladeThemeV1\Support\HomeInternalLinks::get())

@if (!empty($links['posts']))
    <section class="bg-white py-6 lg:py-10" aria-labelledby="home-posts-title">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="mb-4 flex items-end justify-between gap-4">
                <h2 id="home-posts-title" class="text-xl lg:text-2xl font-bold text-gray-900">Cẩm nang &amp; tin tức</h2>
                <a href="{{ route('posts.page') }}" class="shrink-0 text-sm font-semibold text-gray-700 underline hover:text-gray-900">Xem tất cả bài viết</a>
            </div>
            <div class="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-2 lg:grid lg:grid-cols-3 lg:overflow-visible">
                @foreach ($links['posts'] as $post)
                    <article class="w-[78%] shrink-0 snap-start sm:w-[45%] lg:w-auto">
                        <a href="{{ $post['url'] }}" class="group flex h-full flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white">
                            <div class="aspect-[16/9] w-full overflow-hidden bg-gray-100">
                                @if ($post['image'])
                                    <img src="{{ $post['image'] }}" alt="{{ $post['title'] }}" loading="lazy" decoding="async" width="480" height="270" class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105">
                                @endif
                            </div>
                            <div class="flex flex-1 flex-col p-4">
                                <h3 class="line-clamp-2 text-base font-semibold text-gray-900 group-hover:underline">{{ $post['title'] }}</h3>
                                @if ($post['summary'])
                                    <p class="mt-1 line-clamp-2 text-sm text-gray-600">{{ $post['summary'] }}</p>
                                @endif
                                @if ($post['date'])
                                    <time datetime="{{ $post['date'] }}" class="mt-auto pt-2 text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($post['date'])->format('d/m/Y') }}</time>
                                @endif
                            </div>
                        </a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endif

@if (!empty($links['regions']))
    {{-- Nhóm theo tỉnh (HomeInternalLinks::get() — tỉnh nhiều phòng nhất trước): trang chủ nhắm Cần
         Thơ nên chi nhánh/bài viết của tỉnh khác (villa + cẩm nang Đà Lạt) nằm ở khối riêng có tiêu đề
         rõ ràng thay vì lẫn trong cùng danh sách. --}}
    <section class="bg-gray-50 py-6 lg:py-10" aria-labelledby="home-branches-title">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <h2 id="home-branches-title" class="mb-4 text-xl lg:text-2xl font-bold text-gray-900">Khám phá chi nhánh 365 HOME</h2>

            @foreach ($links['regions'] as $province => $region)
                {{-- Dạng @php(...) một dòng — file này đã có @php(...) ở đầu, khối @php nhiều dòng sẽ bị
                     Blade ghép nhầm với nó (xem ghi chú tương tự trong livewire/flash-sale.blade.php). --}}
                @php($regionTitle = str_contains($province, 'Lâm Đồng') ? 'Lưu trú ở Đà Lạt (Lâm Đồng)' : 'Lưu trú tại ' . trim(preg_replace('/^(Thành phố|Tỉnh)\s+/u', '', $province)))
                <div class="{{ $loop->first ? '' : 'mt-8' }}">
                    <h3 class="mb-3 text-lg font-semibold text-gray-900">{{ $regionTitle }}</h3>

                    @if (!empty($region['branches']))
                        <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($region['branches'] as $branch)
                                <li>
                                    <a href="{{ $branch['url'] }}" class="block h-full rounded-xl border border-gray-200 bg-white px-4 py-3 hover:border-gray-400">
                                        <span class="block text-sm font-semibold text-gray-900">{{ $branch['name'] }}</span>
                                        <span class="mt-0.5 block text-xs text-gray-500">{{ $branch['area'] }} · {{ $branch['rooms'] }} phòng</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if (!empty($region['areas']) || !empty($region['posts']))
                        <nav class="mt-3 flex flex-wrap gap-2" aria-label="{{ $regionTitle }}">
                            @foreach ($region['areas'] ?? [] as $area)
                                <a href="{{ $area['url'] }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500 hover:text-gray-900">{{ $area['name'] }}</a>
                            @endforeach
                            @foreach ($region['posts'] ?? [] as $post)
                                <a href="{{ $post['url'] }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500 hover:text-gray-900">{{ $post['title'] }}</a>
                            @endforeach
                        </nav>
                    @endif
                </div>
            @endforeach

            <nav class="mt-8 flex flex-wrap gap-2" aria-label="Liên kết khác">
                <a href="{{ route('posts.page') }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500 hover:text-gray-900">Cẩm nang du lịch</a>
                <a href="{{ route('partner-onboarding.page') }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500 hover:text-gray-900">Đăng ký hợp tác</a>
            </nav>
        </div>
    </section>
@endif
