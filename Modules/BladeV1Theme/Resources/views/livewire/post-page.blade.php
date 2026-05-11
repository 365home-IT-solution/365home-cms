<div>
    <div class="flex flex-col lg:flex-row gap-4 mt-5">

        @if ($showSidebar)
            @if ($locateSidebar == 'left' || empty($locateSidebar))
                <div class="w-full lg:w-1/4">

                    @if (!empty($showCategories == '1'))
                        @include('bladethemev1::components.posts.sidebar.show-categories')
                    @endif

                    @if (!empty($showSocial ?? ''))
                        @include('bladethemev1::components.posts.sidebar.social')
                    @endif

                    @if (!empty($showPostNew == '1'))
                        @include('bladethemev1::components.posts.sidebar.post-new')
                    @endif

                </div>
            @endif
        @endif

        <div class="w-full {{ $showSidebar ? 'lg:w-3/4' : '' }}">

            @if ($showSidebar && $locateSidebar == 'right' && !empty($showCategories == '1'))
                <div class="block lg:hidden mb-4">
                    @include('bladethemev1::components.posts.sidebar.show-categories')
                </div>
            @endif

            <div class="mb-4 bg-white rounded shadow-lg overflow-hidden border border-gray-100">
                @include('bladethemev1::components.posts.filter-post')
            </div>

            <div class="space-y-4">
                @switch($currentStyle ?? 'grid')
                    @case('grid')
                        @include('bladethemev1::components.posts.grid-style')
                    @break

                    @case('list')
                        @include('bladethemev1::components.posts.list-style')
                    @break
                @endswitch
            </div>
            <x-bladethemev1::paginate :items="$posts" />
        </div>

        @if ($showSidebar)
            @if ($locateSidebar == 'right')
                <div class="w-full lg:w-1/4">

                    @if (!empty($showCategories == '1'))
                        <div class="hidden lg:block mb-4">
                            @include('bladethemev1::components.posts.sidebar.show-categories')
                        </div>
                    @endif

                    @if (!empty($showSocial ?? ''))
                        @include('bladethemev1::components.posts.sidebar.social')
                    @endif

                    @if (!empty($showPostNew == '1'))
                        @include('bladethemev1::components.posts.sidebar.post-new')
                    @endif

                </div>
            @endif
        @endif
    </div>

    <style>
        .bgCate {
            background-color: white;
            color: {{ $primaryColor }};
            border: 1px solid{{ $primaryColor }};
        }

        .bgCate:hover {
            background-color: {{ $primaryColor }};
            color: white;
        }

        .bgButton {
            background-color: white;
            color: {{ $primaryColor }};
            border: 1px solid{{ $primaryColor }};
        }

        .bgButton:hover {
            color: white;
        }

        .bgPrimaWhite {
            color: white;
            background-color: {{ $primaryColor }};
        }

        .pagiItem {
            background-color: {{ $primaryColor }};
            color: white;
            border: 1px solid{{ $primaryColor }};
        }

        .paginate {
            background-color: white;
            color: {{ $primaryColor }};
            border: 1px solid {{ $primaryColor }};
        }

        .paginate:hover {
            background-color: {{ $primaryColor }};
            color: white;
            border: 1px solid {{ $primaryColor }};
        }

        .resetFilter {
            background-color: {{ $primaryColor }};
            color: white;
            border: 1px solid{{ $primaryColor }};
        }

        .filter-container {
            max-height: 300px;
            /* Chiều cao tối đa của phần cuộn */
            overflow-y: auto;
            /* Bật cuộn dọc khi nội dung vượt quá chiều cao */
            background-color: #fff;
            /* Màu nền */
            position: relative;
            /* Đặt vị trí tương đối để các phần tử con có thể sử dụng */
        }

        /* Thanh cuộn tùy chỉnh */
        .filter-container::-webkit-scrollbar {
            width: 8px;
            /* Chiều rộng thanh cuộn */
        }

        .filter-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            /* Màu nền cho vùng thanh cuộn */
            border-radius: 10px;
            /* Bo góc cho vùng thanh cuộn */
        }

        .filter-container::-webkit-scrollbar-thumb {
            background: {{ $primaryColor }};
            /* Màu cho thanh cuộn */
            border-radius: 10px;
            /* Bo góc cho thanh cuộn */
        }

        .filter-container::-webkit-scrollbar-thumb:hover {
            background: #555;
            /* Màu cho thanh cuộn khi di chuột */
        }

        .resetFilter:hover {
            background-color: white;
            color: {{ $primaryColor }};
            border: 1px solid{{ $primaryColor }};
        }
    </style>

</div>
