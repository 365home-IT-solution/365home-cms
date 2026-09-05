<div class="max-w-screen-xl mx-auto md:px-8 px-4 py-8 flex flex-col md:flex-row space-y-4 md:space-y-0 md:space-x-8">

    @if (!empty($tocItems))
        <!-- Nội dung: cột trái sticky, chỉ hiện ở lg+ (dưới lg dùng nút nổi + modal, xem cuối trang) -->
        <aside class="hidden lg:block lg:w-64 flex-shrink-0">
            <nav id="toc-sticky" aria-label="Nội dung bài viết" class="sticky top-20 rounded-2xl border overflow-hidden"
                 style="border-color: rgba(var(--color-primary-rgb), 0.25); background-color: rgba(var(--color-primary-rgb), 0.04);">
                <div class="flex items-center gap-2 px-4 py-3 font-semibold text-primary">
                    <x-heroicon-o-list-bullet class="w-5 h-5 flex-shrink-0" />
                    Nội dung
                </div>
                <div class="max-h-[65vh] overflow-y-auto border-t px-4 pb-4 pt-3"
                     style="border-color: rgba(var(--color-primary-rgb), 0.15);">
                    <x-bladethemev1::posts.toc-list :items="$tocItems" />
                </div>
            </nav>
        </aside>
    @endif

    <!-- Main Content -->
    <main class="flex-1 min-w-0 bg-white">
        @if ($post)
            <article class="bg-white overflow-hidden">
                <h1 class="md:text-2xl text-xl font-bold text-#E3A008 uppercase text-primary">
                    {{ $post->title }}</h1>

                @php
                    $featuredMedia = $post->getFirstMedia('Ảnh chính');
                    $featuredImage = $featuredMedia?->getUrl() ?? '';
                    $featuredImageWidth = $featuredMedia?->getCustomProperty('width');
                    $featuredImageHeight = $featuredMedia?->getCustomProperty('height');

                    // srcset chỉ liệt kê conversion đã thực sự sinh ra (post cũ upload trước khi
                    // Post::registerMediaConversions() tồn tại sẽ chưa có — cần chạy lại
                    // `php artisan media-library:regenerate` để backfill, xem ghi chú ở Post.php).
                    // Không có conversion nào thì bỏ hẳn srcset, dùng $featuredImage gốc như cũ.
                    $featuredImageSrcset = collect(['card' => 480, 'wide' => 1080, 'full' => 1440])
                        ->filter(fn ($width, $conversion) => $featuredMedia?->hasGeneratedConversion($conversion))
                        ->map(fn ($width, $conversion) => $featuredMedia->getUrl($conversion) . ' ' . $width . 'w')
                        ->implode(', ');
                @endphp

                @if($featuredImage)
                    <img src="{{ $featuredImage }}" alt="{{ $post->title }}"
                         @if($featuredImageWidth) width="{{ $featuredImageWidth }}" @endif
                         @if($featuredImageHeight) height="{{ $featuredImageHeight }}" @endif
                         @if($featuredImageSrcset)
                             srcset="{{ $featuredImageSrcset }}"
                             sizes="(max-width: 1023px) 100vw, 768px"
                         @endif
                         class="w-full h-auto rounded-lg my-4 object-cover max-h-[480px]"
                         loading="lazy">
                @endif

                <div class="flex flex-wrap items-center justify-between gap-4 my-4 border-gray-300 py-3 border-b">
                    <!-- Ngày tạo -->
                    <span class="flex items-center gap-2 text-gray-700">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                             stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                        </svg>
                        <span class="text-sm font-medium">Ngày tạo: {{ \Carbon\Carbon::parse($post->created_at)->format('d/m/Y') }}</span>
                    </span>

                    @livewire('bladethemev1::post-rating', ['postId' => $post->id], key('post-rating-' . $post->id))
                </div>

                <div class="">

                    {{-- Cố tình không dùng class "post-summary" ở đây: class đó bị giới hạn
                         -webkit-line-clamp: 3 dòng trong app.scss (dùng cho preview tóm tắt ở thẻ
                         bài viết/overlay danh sách) — trang chi tiết cần hiện trọn vẹn mô tả, chữ
                         nhiều bao nhiêu cũng không nên bị cắt/dính sát viền dashed bên dưới. --}}
                    <div class="post-intro text-gray-700 text-sm sm:text-base mb-6 p-4 sm:p-5 rounded-2xl"
                         style="background-color: rgba(var(--color-primary-rgb), 0.08); border: 2px dashed var(--color-primary);">
                        {!! $post->summary !!}
                    </div>
                    <div class="post-content prose max-w-none">
                        {!! $contentWithIds !!}
                    </div>

                    <div>
                        @if ($post->tags->isNotEmpty())
                            <div class="mt-6 pt-2 border-t border-gray-200">
                                <span class="text-sm font-semibold text-gray-700 mr-2">Thẻ:</span>
                                @foreach ($post->tags as $tag)
                                    <span
                                        class="inline-block rounded-full px-3 py-1 text-sm font-semibold mr-2 mb-2 text-primary">
                                        {{ $tag->name }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @if ($post->categories->isNotEmpty())
                            <div class="pt-2">
                                <span class="text-sm font-semibold text-gray-700 mr-2">Danh mục:</span>
                                @foreach ($post->categories as $category)
                                    <a href="{{ url('/bai-viet') }}?danh-muc={{ urlencode($category->name) }}"
                                        class="inline-block rounded-full px-3 py-1 text-sm font-semibold mr-2 mb-2 text-primary hover:underline">
                                        {{ $category->name }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="flex space-x-2 mt-5">
                        <div
                            class="flex items-center justify-center me-2 text-sm font-semibold text-gray-900 border border-gray-300 rounded-md p-2 bg-white shadow-sm">
                            <i class="fas fa-share-alt me-2"></i>
                            Chia sẻ:
                        </div>
                        {{-- facebook --}}
                        <button class="relative group transition-all duration-500 hover:-translate-y-2">
                            <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($url) }}"
                               target="_blank">
                                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36"
                                     viewBox="0 0 93 92" fill="none">
                                    <rect x="1.13867" width="91.5618" height="91.5618" rx="15"
                                          fill="#337FFF" />
                                    <path
                                        d="M57.4233 48.6403L58.7279 40.3588H50.6917V34.9759C50.6917 32.7114 51.8137 30.4987 55.4013 30.4987H59.1063V23.4465C56.9486 23.1028 54.7685 22.9168 52.5834 22.8901C45.9692 22.8901 41.651 26.8626 41.651 34.0442V40.3588H34.3193V48.6403H41.651V68.671H50.6917V48.6403H57.4233Z"
                                        fill="white" />
                                </svg>
                            </a>
                            <span
                                class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 w-auto px-2 py-1 text-sm text-white bg-black rounded-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 whitespace-nowrap">
                                Chia sẻ Facebook
                                <span
                                    class="absolute bottom-0 left-1/2 -translate-x-1/2 translate-y-full w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-black"></span>
                            </span>
                        </button>

                        {{-- X --}}
                        <button class="relative group transition-all duration-500 hover:-translate-y-2">
                            <a href="https://twitter.com/intent/tweet?url={{ urlencode($url) }}&text={{ $title }}"
                               target="_blank">
                                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36"
                                     viewBox="0 0 93 92" fill="none">
                                    <rect x="0.138672" width="91.5618" height="91.5618" rx="15"
                                          fill="black" />
                                    <path
                                        d="M50.7568 42.1716L69.3704 21H64.9596L48.7974 39.383L35.8887 21H21L40.5205 48.7983L21 71H25.4111L42.4788 51.5869L56.1113 71H71L50.7557 42.1716H50.7568ZM44.7152 49.0433L42.7374 46.2752L27.0005 24.2492H33.7756L46.4755 42.0249L48.4533 44.7929L64.9617 67.8986H58.1865L44.7152 49.0443V49.0433Z"
                                        fill="white" />
                                </svg>
                            </a>
                            <span
                                class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 w-auto px-2 py-1 text-sm text-white bg-black rounded-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 whitespace-nowrap">
                                Chia sẻ Twitter
                                <span
                                    class="absolute bottom-0 left-1/2 -translate-x-1/2 translate-y-full w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-black"></span>
                            </span>
                        </button>

                        {{-- Mail --}}
                        <button class="relative group transition-all duration-500 hover:-translate-y-2">
                            <a href="mailto:?subject={{ $title }}&body={{ urlencode($url) }}"
                               target="_blank">
                                <svg width="36" height="36" viewBox="0 0 92 92" fill="none"
                                     xmlns="http://www.w3.org/2000/svg">
                                    <rect x="0.638672" y="0.5" width="90.5618" height="90.5618" rx="14.5"
                                          fill="white" stroke="#C4CFE3" />
                                    <path
                                        d="M22.0065 66.1236H30.4893V45.5227L18.3711 36.4341V62.4881C18.3711 64.4997 20.001 66.1236 22.0065 66.1236Z"
                                        fill="#4285F4" />
                                    <path
                                        d="M59.5732 66.1236H68.056C70.0676 66.1236 71.6914 64.4937 71.6914 62.4881V36.4341L59.5732 45.5227"
                                        fill="#34A853" />
                                    <path
                                        d="M59.5732 29.7693V45.5229L71.6914 36.4343V31.587C71.6914 27.0912 66.5594 24.5282 62.9663 27.2245"
                                        fill="#FBBC04" />
                                    <path
                                        d="M30.4893 45.5227V29.769L45.0311 40.6754L59.5729 29.769V45.5227L45.0311 56.429"
                                        fill="#EA4335" />
                                    <path
                                        d="M18.3711 31.587V36.4343L30.4893 45.5229V29.7693L27.0962 27.2245C23.4971 24.5282 18.3711 27.0912 18.3711 31.587Z"
                                        fill="#C5221F" />
                                </svg>
                            </a>
                            <span
                                class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 w-auto px-2 py-1 text-sm text-white bg-black rounded-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 whitespace-nowrap">
                                Gửi Email
                                <span
                                    class="absolute bottom-0 left-1/2 -translate-x-1/2 translate-y-full w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-black"></span>
                            </span>
                        </button>

                        {{-- telegram --}}
                        <button class="relative group transition-all duration-500 hover:-translate-y-2">
                            <a href="https://t.me/share/url?url={{ urlencode($url) }}&text={{ $title }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36"
                                     viewBox="0 0 92 93" fill="none">
                                    <rect x="0.138672" y="1" width="91.5618" height="91.5618" rx="15"
                                          fill="#34AADF" />
                                    <path
                                        d="M25.0881 43.5652C25.0881 43.5652 43.716 35.7194 50.1765 32.9567C52.6532 31.8518 61.0518 28.3155 61.0518 28.3155C61.0518 28.3155 64.9282 26.7685 64.6052 30.5256C64.4974 32.0728 63.6361 37.4874 62.7747 43.3442C61.4825 51.6322 60.0827 60.6935 60.0827 60.6935C60.0827 60.6935 59.8674 63.2352 58.0369 63.6772C56.2065 64.1192 53.1914 62.1302 52.6532 61.6881C52.2223 61.3566 44.5774 56.3838 41.7778 53.9527C41.0241 53.2897 40.1627 51.9637 41.8854 50.4166C45.7618 46.7699 50.3919 42.2392 53.1914 39.3661C54.4836 38.04 55.7757 34.9459 50.3919 38.703C42.7469 44.1178 35.2096 49.201 35.2096 49.201C35.2096 49.201 33.4868 50.306 30.2565 49.3115C27.0261 48.317 23.2575 46.9909 23.2575 46.9909C23.2575 46.9909 20.6734 45.3334 25.0881 43.5652Z"
                                        fill="white" />
                                </svg>
                            </a>
                            <span
                                class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 w-auto px-2 py-1 text-sm text-white bg-black rounded-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 whitespace-nowrap">
                                Chia sẻ Telegram
                                <span
                                    class="absolute bottom-0 left-1/2 -translate-x-1/2 translate-y-full w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-black"></span>
                            </span>
                        </button>
                    </div>
                </div>
                <div class="py-6">
                    @livewire('bladethemev1::comment', [
                    'commentableId' => $post->id,
                    'commentableType' => get_class($post),
                    ])
                </div>
            </article>
        @else
            <p class="text-center text-gray-600">Không tìm thấy bài viết.</p>
        @endif
    </main>

    <!-- Sidebar -->
    <aside class="w-full md:w-1/3 lg:w-1/4 bg-white p-4">
        <nav class="space-y-4">
            @if ($relatedPosts->isNotEmpty())
                <div class="space-y-6">
                    <h3 class="text-lg font-semibold text-gray-900">Bài viết liên quan</h3>
                    @foreach ($relatedPosts as $relatedPost)
                        <div class="flex items-start space-x-4">
                            <img src="{{ $relatedPost->media->first()?->getUrl() }}" alt="{{ $relatedPost->title }}"
                                 class="w-16 h-16 rounded-lg object-cover flex-shrink-0">
                            <a href="{{ route('post.detail', ['slug' => $relatedPost->slug]) }}"
                               class="text-sm font-semibold text-black hover:text-primary">
                                {{ \Illuminate\Support\Str::limit($relatedPost->title, 60) }}
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </nav>
    </aside>
</div>

@if (!empty($tocItems))
    <!-- Nội dung: dưới lg dùng nút nổi + modal thay cho cột sticky (ẩn ở lg+ vì đã có cột trái).
         z-index > 1000 vì .bottom-navigation-1 (thanh menu dưới cùng site) đứng ở z-index:1000,
         che khuất mọi thứ z thấp hơn nằm trong vùng của nó. Nút tròn ghim giữa mép trái màn hình
         (không dính đáy, tránh chồng lên thanh menu dưới); modal hiện dạng thẻ nổi giữa màn hình
         thay vì bottom-sheet. -->
    <div class="lg:hidden" x-data="{ open: false }">
        <button type="button" @click="open = true" aria-label="Mở mục lục nội dung"
                class="fixed left-4 top-1/2 -translate-y-1/2 z-[1010] flex h-12 w-12 items-center justify-center rounded-full text-white shadow-lg"
                style="background-color: var(--color-primary);">
            <x-heroicon-o-list-bullet class="w-6 h-6 flex-shrink-0" />
        </button>

        <div x-show="open" x-cloak x-transition.opacity @click="open = false"
             class="fixed inset-0 z-[1005] bg-black/40"></div>

        <div x-show="open" x-cloak x-transition
             class="fixed left-1/2 top-1/2 z-[1010] w-[calc(100%-3rem)] max-w-md -translate-x-1/2 -translate-y-1/2 max-h-[85vh] overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
            <div class="mb-4 flex items-center justify-between">
                <span class="flex items-center gap-2 text-lg font-semibold text-primary">
                    <x-heroicon-o-list-bullet class="w-6 h-6 flex-shrink-0" />
                    Nội dung
                </span>
                <button type="button" @click="open = false" aria-label="Đóng">
                    <x-heroicon-o-x-mark class="w-6 h-6 text-gray-500" />
                </button>
            </div>
            <div @click="if ($event.target.closest('.toc-link')) open = false">
                <x-bladethemev1::posts.toc-list :items="$tocItems" />
            </div>
        </div>
    </div>
@endif

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Ảnh có caption trong TinyMCE (image_caption: true) được bọc <figure><img><figcaption>.
        // Biên tập viên hay chỉ điền caption mà quên ô Alt text riêng trong dialog ảnh — điền lại
        // alt từ caption cho những ảnh còn thiếu, tránh mất điểm accessibility/SEO ảnh.
        document.querySelectorAll('.post-content figure').forEach(function(figure) {
            const img = figure.querySelector('img');
            const figcaption = figure.querySelector('figcaption');
            if (img && figcaption && !img.getAttribute('alt')) {
                img.setAttribute('alt', figcaption.textContent.trim());
            }
        });

        // Id của heading + cây mục lục giờ được build sẵn ở server (TableOfContents::build, xem
        // PostDetail.php) — JS chỉ còn lo phần tương tác: cuộn mượt, thu/phóng khung, và tô đậm
        // mục đang đọc theo vị trí cuộn (progressive enhancement, không ảnh hưởng gì nếu JS lỗi).
        const headings = document.querySelectorAll('.post-content h1, .post-content h2, .post-content h3');
        headings.forEach(heading => heading.classList.add('text-primary'));

        // Header dùng position:sticky và tự co giãn chiều cao khi cuộn (xem header-hero-sticky),
        // nên top cố định bằng Tailwind (top-20) sẽ lúc đúng lúc không — đo chiều cao header thật
        // và ghim cột "Nội dung" ngay dưới nó, cập nhật lại mỗi khi cuộn/resize.
        const tocSticky = document.getElementById('toc-sticky');
        const stickyHeader = document.querySelector('.header-hero-sticky');

        if (tocSticky && stickyHeader) {
            const syncTocTop = () => {
                tocSticky.style.top = (stickyHeader.offsetHeight + 16) + 'px';
            };

            syncTocTop();
            window.addEventListener('resize', syncTocTop);

            let tocTopTicking = false;
            window.addEventListener('scroll', () => {
                if (tocTopTicking) return;
                tocTopTicking = true;
                requestAnimationFrame(() => {
                    syncTocTop();
                    tocTopTicking = false;
                });
            });
        }

        // Nội dung giờ có 2 bản trong DOM (cột sticky lg+ và modal mobile, xem trên) — cùng
        // chọn hết qua .toc-link, bản nào đang ẩn theo breakpoint thì không ai bấm được nên
        // không cần phân biệt.
        const tocLinks = document.querySelectorAll('.toc-link');

        tocLinks.forEach(link => {
            link.addEventListener('click', function(event) {
                event.preventDefault();
                const targetId = this.getAttribute('href').slice(1);
                const targetElement = document.getElementById(targetId);
                if (!targetElement) return;

                const offset = 70;
                const offsetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset - offset;

                window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                history.pushState(null, null, `#${targetId}`);
            });
        });

        if ('IntersectionObserver' in window && tocLinks.length > 0) {
            const tocHeadings = Array.from(tocLinks)
                .map(link => document.getElementById(link.getAttribute('href').slice(1)))
                .filter(Boolean);

            const setActiveLink = (id) => {
                tocLinks.forEach(link => {
                    link.classList.toggle('toc-link-active', link.getAttribute('href') === `#${id}`);
                });
            };

            const observer = new IntersectionObserver((entries) => {
                const visible = entries.find(entry => entry.isIntersecting);
                if (visible) {
                    setActiveLink(visible.target.id);
                }
            }, { rootMargin: '-80px 0px -70% 0px' });

            tocHeadings.forEach(heading => observer.observe(heading));
        }
    });
</script>
