<style>
    /* CSS cho post-content */
    .post-content {
        font-size: 16px;
        line-height: 1.6;
        color: #333;
    }

    /* Định dạng các thẻ heading */
    .post-content h1 {
        font-size: 2.5em;
        font-weight: bold;
        margin: 1.5em 0 0.8em;
        line-height: 1.2;
    }

    .post-content h2 {
        font-size: 2em;
        font-weight: bold;
        margin: 1.3em 0 0.7em;
        line-height: 1.3;
    }

    .post-content h3 {
        font-size: 1.5em;
        font-weight: bold;
        margin: 1.2em 0 0.6em;
        line-height: 1.4;
    }

    .post-content h4 {
        font-size: 1.25em;
        font-weight: bold;
        margin: 1.1em 0 0.5em;
        line-height: 1.4;
    }

    .post-content h5 {
        font-size: 1.1em;
        font-weight: bold;
        margin: 1em 0 0.4em;
        line-height: 1.5;
    }

    .post-content h6 {
        font-size: 1em;
        font-weight: bold;
        margin: 0.9em 0 0.3em;
        line-height: 1.5;
    }

    /* Định dạng paragraph */
    .post-content p {
        margin: 1em 0;
        font-size: 16px;
        line-height: 1.6;
    }

    /* Định dạng danh sách */
    .post-content ul,
    .post-content ol {
        margin: 1em 0;
        padding-left: 2em;
    }

    .post-content li {
        margin: 0.5em 0;
        font-size: 16px;
        line-height: 1.6;
    }

    /* Định dạng blockquote */
    .post-content blockquote {
        margin: 1.5em 0;
        padding: 1em 1.5em;
        border-left: 4px solid #ddd;
        background-color: #f9f9f9;
        font-style: italic;
        font-size: 16px;
    }

    /* Định dạng code */
    .post-content code {
        background-color: #f4f4f4;
        padding: 2px 4px;
        border-radius: 3px;
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
        font-size: 14px;
    }

    .post-content pre {
        background-color: #f4f4f4;
        padding: 1em;
        border-radius: 5px;
        overflow-x: auto;
        margin: 1em 0;
    }

    .post-content pre code {
        background-color: transparent;
        padding: 0;
        font-size: 14px;
    }

    /* Định dạng bảng */
    .post-content table {
        width: 100%;
        border-collapse: collapse;
        margin: 1.5em 0;
        font-size: 16px;
    }

    .post-content th,
    .post-content td {
        padding: 8px 12px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }

    .post-content th {
        background-color: #f5f5f5;
        font-weight: bold;
    }

    /* Định dạng hình ảnh đơn lẻ */
    .post-content img {
        max-width: 100%;
        height: auto;
        display: block;
        margin: 1.5em auto;
        border-radius: 5px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    /* Gallery flex cho hình ảnh liền kề (không có text phân cách) */
    .post-content img + img,
    .post-content img + br + img,
    .post-content img + p:empty + img {
        display: inline-block;
        margin: 0.5em;
        width: calc(50% - 1em);
        vertical-align: top;
    }

    /* Cho 3 hình liền kề */
    .post-content img + img + img,
    .post-content img + br + img + img,
    .post-content img + p:empty + img + img {
        width: calc(33.33% - 1em);
    }

    /* Cho 4 hình liền kề trở lên */
    .post-content img + img + img + img,
    .post-content img + br + img + img + img,
    .post-content img + p:empty + img + img + img {
        width: calc(25% - 1em);
    }

    /* Container cho gallery images */
    .post-content p:has(img + img),
    .post-content div:has(img + img) {
        display: flex;
        flex-wrap: wrap;
        gap: 1em;
        justify-content: center;
        margin: 1.5em 0;
    }

    /* Responsive cho gallery */
    @media (max-width: 768px) {
        .post-content img + img,
        .post-content img + br + img,
        .post-content img + p:empty + img {
            width: calc(50% - 0.5em);
            margin: 0.25em;
        }

        .post-content img + img + img,
        .post-content img + br + img + img,
        .post-content img + p:empty + img + img {
            width: calc(50% - 0.5em);
        }

        .post-content img + img + img + img,
        .post-content img + br + img + img + img,
        .post-content img + p:empty + img + img + img {
            width: calc(50% - 0.5em);
        }
    }

    @media (max-width: 480px) {
        .post-content img + img,
        .post-content img + br + img,
        .post-content img + p:empty + img,
        .post-content img + img + img,
        .post-content img + br + img + img,
        .post-content img + p:empty + img + img,
        .post-content img + img + img + img,
        .post-content img + br + img + img + img,
        .post-content img + p:empty + img + img + img {
            width: 100%;
            margin: 0.5em 0;
        }
    }

    /* Định dạng links */
    .post-content a {
        color: #007cba;
        text-decoration: none;
        font-size: 16px;
    }

    .post-content a:hover {
        text-decoration: underline;
    }

    /* Định dạng hr */
    .post-content hr {
        margin: 2em 0;
        border: none;
        height: 1px;
        background-color: #ddd;
    }

    /* Định dạng strong và em */
    .post-content strong {
        font-weight: bold;
        font-size: 16px;
    }

    .post-content em {
        font-style: italic;
        font-size: 16px;
    }

    /* Giữ lại CSS TOC của bạn */
    .toc-parent a {
        font-size: 18px;
        font-weight: bold;
    }

    .toc-item a {
        font-size: 14px;
    }

    .toc-parent ul .toc-item a {
        font-weight: normal;
    }

    .toc-item {
        margin-bottom: 5px;
    }

    .toggle-btn {
        font-weight: bold;
        font-size: 16px;
        margin-right: 5px;
        cursor: pointer;
    }
</style>
<div class="max-w-screen-xl mx-auto md:px-8 px-4 py-8 flex flex-col md:flex-row space-y-4 md:space-y-0 md:space-x-8">

    <!-- Main Content -->
    <main class="flex-1 bg-white">
        @if ($post)
            <div id="seo-data" data-seo-title="{{ $post->seo_title }}" data-seo-description="{{ $post->seo_description }}"
                 data-seo-keyword="{{ $post->seo_keywords }}">
            </div>

            <div class="flex gap-2">
                @foreach ($post->categories as $category)
                    <span class="block px-4 py-2 mb-2 text-sm font-medium bg-primary text-white rounded-md shadow-md">
                        {{ $category->name }}
                    </span>
                @endforeach
            </div>


            <article class="bg-white overflow-hidden">
                <h1 class="md:text-2xl text-xl font-bold text-#E3A008 uppercase text-primary">
                    {{ $post->title }}</h1>

                @php
                    $featuredImage = $post->getFirstMediaUrl('Ảnh chính');
                    $wordCount = str_word_count(strip_tags($post->content ?? ''));
                    $readingMinutes = max(1, (int) ceil($wordCount / 200));
                @endphp

                @if($featuredImage)
                    <img src="{{ $featuredImage }}" alt="{{ $post->title }}"
                         class="w-full hidden h-auto rounded-lg my-4 object-cover max-h-[480px]"
                         loading="lazy">
                @endif

                <div class="flex hidden flex-wrap justify-between gap-4 md:gap-6 my-4 border-gray-300 py-3 border-b">
                    <div class="flex flex-wrap items-center gap-4">
                        <!-- Author -->
                        <div class="flex items-center gap-2 text-gray-700">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                            <p class="text-sm font-medium">Tác giả: {{ $post->user->fullname ?? $post->user->name ?? 'Ẩn danh' }}</p>
                        </div>

                        <!-- Date -->
                        <span class="flex items-center gap-2 text-gray-700">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                            </svg>
                            <span class="text-sm font-medium">{{ \Carbon\Carbon::parse($post->created_at)->format('d/m/Y') }}</span>
                        </span>

                        @if($post->updated_at && $post->updated_at->gt($post->created_at->addMinutes(5)))
                            <!-- Updated date -->
                            <span class="flex items-center gap-2 text-gray-500">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                     stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                <span class="text-xs text-gray-500">Cập nhật: {{ \Carbon\Carbon::parse($post->updated_at)->format('d/m/Y') }}</span>
                            </span>
                        @endif

                        <!-- Reading time -->
                        <span class="flex items-center gap-2 text-gray-500">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <span class="text-xs text-gray-500">{{ $readingMinutes }} phút đọc</span>
                        </span>
                    </div>
                </div>

                <div class="">

                    <div class="post-summary text-gray-700 mb-6" style="font-style: italic;">
                        {!! $post->summary !!}
                    </div>
                    <div class="toc-wrap md:p-4 p-2" style="border-radius: 10px; ">
                        <div class="text-center md:text-2xl text-xl cursor-pointer flex justify-between items-center text-primary"
                             id="toc-toggle">
                            Mục lục
                            <span id="toggle-icon" class="ml-2 text-xl">▼</span> <!-- Biểu tượng mũi tên -->
                        </div>
                        <div id="toc" class="hidden ">
                            <!-- Mục lục sẽ được tạo ra ở đây -->
                        </div>
                    </div>

                    <div class="post-content prose max-w-none">
                        {!! $post->content !!}
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
                                    <span
                                        class="inline-block rounded-full px-3 py-1 text-sm font-semibold mr-2 mb-2 text-primary">
                                        {{ $category->name }}
                                    </span>
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

            @if ($relatedPosts->isNotEmpty())
                <div class="mt-12">
                    <h2 class="text-xl font-semibold mb-6 text-gray-900">Bài viết liên quan</h2>
                    <div class="grid gap-6 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($relatedPosts as $relatedPost)
                            @include('bladethemev1::components.posts.card', [
                                'post' => $relatedPost,
                            ])
                        @endforeach
                    </div>
                </div>
            @endif
        @else
            <p class="text-center text-gray-600">Không tìm thấy bài viết.</p>
        @endif
    </main>

    <!-- Sidebar -->
    <aside class="w-full md:w-1/3 lg:w-1/4 bg-white p-4">
        <nav class="space-y-4">
            <div class="w-full sm:w-auto relative">
                @livewire('bladethemev1::post-search')
            </div>
            <div class="space-y-6">
                <h3 class="text-lg font-semibold text-gray-900">Bài viết mới nhất</h3>
                @foreach ($latestPosts as $post)
                    <div class="flex items-start space-x-4">
                        <img src="{{ $post->media->first()->getUrl() }}" alt="{{ $post->title }}"
                             class="w-16 h-16 rounded-lg object-cover">
                        <div>
                            @if ($post->categories->isNotEmpty())
                                <div class="mb-1">
                                    <span class="text-xs font-semibold text-primary">
                                        {{ $post->categories->first()->name }}
                                    </span>
                                </div>
                            @endif
                            <a href="{{ route('post.detail', ['slug' => $post->slug]) }}"
                               class="text-sm font-semibold text-black hover:text-primary">
                                {{ \Illuminate\Support\Str::limit($post->title, 50) }}
                            </a>
                            <p class="text-xs text-gray-500 mt-1 flex items-center space-x-1">
                                <i class="far fa-calendar-alt"></i>
                                <span>{{ $post->published_at?->translatedFormat('d \t\h\á\n\g m, Y') }}</span>
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </nav>
    </aside>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const headings = document.querySelectorAll(
            '.post-content h1, .post-content h2, .post-content h3, .post-content h4, .post-content h5, .post-content h6'
        );
        const tocContainer = document.querySelector('#toc');
        const tocWrap = document.querySelector('.toc-wrap');

        if (headings.length === 0 || !tocContainer || !tocWrap) {
            console.log('No headings found or TOC elements missing, hiding TOC');
            if (tocWrap) {
                tocWrap.style.display = 'none';
            }
            return;
        }

        const startingLevel = parseInt(headings[0].tagName[1]); // Get the first heading level
        const toc = document.createElement('ul');
        toc.classList.add('space-y-2', 'px-2');
        toc.style.listStyle = 'auto';
        const prevLevels = Array(6).fill(0); // Tracks heading levels

        let lastList = toc; // Tracks the last list element (for nesting)

        headings.forEach(heading => {
            const level = parseInt(heading.tagName[1]);

            // Update the numbering for the current level
            prevLevels[level - 1]++;
            for (let i = level; i < prevLevels.length; i++) {
                prevLevels[i] = 0; // Reset lower levels
            }

            const newHeadingId = `${heading.textContent.toLowerCase().replace(/ /g, '-')}`;
            heading.id = newHeadingId;

            const anchor = document.createElement('a');
            anchor.setAttribute('href', `#${newHeadingId}`);
            anchor.textContent = heading.textContent;

            anchor.addEventListener('click', (event) => {
                event.preventDefault();
                const targetId = event.target.getAttribute('href').slice(1);
                const targetElement = document.getElementById(targetId);

                const offset = 70;
                const elementPosition = targetElement.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - offset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: "smooth"
                });

                history.pushState(null, null, `#${targetId}`);
            });

            const listItem = document.createElement('li');
            listItem.classList.add('md:text-lg', 'text-md');
            listItem.appendChild(anchor);

            // Determine nesting level and append accordingly
            if (level > startingLevel) {
                const lastItemSublist = document.createElement('ul');
                lastList.appendChild(lastItemSublist);
                lastList = lastItemSublist;
            } else if (level < startingLevel) {
                lastList = toc;
            }

            lastList.appendChild(listItem);
        });

        tocContainer.innerHTML = '';
        tocContainer.appendChild(toc);

        // Add toggle feature for parents with more than 2 children
        const parents = document.querySelectorAll('.toc-parent');
        parents.forEach(parent => {
            const subList = parent.querySelector('ul');
            if (subList && subList.children.length > 1) {
                // Add the toggle functionality
                const toggleButton = document.createElement('span');
                toggleButton.textContent = '▼';
                toggleButton.classList.add('toggle-btn');
                toggleButton.style.cursor = 'pointer';
                toggleButton.style.marginRight = '5px';

                toggleButton.addEventListener('click', function() {
                    const isHidden = subList.style.display === 'none';
                    subList.style.display = isHidden ? 'block' : 'none';
                    toggleButton.textContent = isHidden ? '▲' : '▼';
                });

                parent.prepend(toggleButton); // Insert before the anchor
                subList.style.display = 'none'; // Initially collapse the list
            }
        });

        // Change heading colors based on levels
        headings.forEach(heading => {
            if (heading.tagName === 'H1' || heading.tagName === 'H2' || heading.tagName === 'H3') {
                heading.classList.add('text-primary');
            }
        });

        // Toggle functionality for TOC
        const tocToggle = document.getElementById('toc-toggle');
        const toggleIcon = document.getElementById('toggle-icon');

        tocToggle.addEventListener('click', function() {
            tocContainer.classList.toggle('hidden');
            toggleIcon.textContent = tocContainer.classList.contains('hidden') ? '▼' : '▲';
        });
    });
</script>
