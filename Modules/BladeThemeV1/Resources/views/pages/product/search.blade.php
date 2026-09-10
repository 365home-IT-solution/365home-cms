@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @php
        // ?view=branches — panel bên phải hiển thị danh sách phòng + bảng đặt khung giờ của chi
        // nhánh được chọn (thay cho bản đồ, không có ý nghĩa khi đang liệt kê chi nhánh). Livewire
        // Book component mount 1 lần với config rỗng, "load-branch" (search-results.js) nạp lại
        // dữ liệu chi nhánh mỗi khi người dùng bấm 1 card — xem Book::loadBranch(). /{type}/
        // {location} (bất kỳ loại hình nào — homestay/khach-san/mini-house/villa/nha-nghi/chung-cu,
        // xem BranchBookConfig::typeUrlSlugs()) là URL rút gọn của /s/{location}?view=branches
        // (không mang query ?view=) nên ngầm định luôn ở chế độ này — cùng luật với isBranchesView()
        // trong search-results.js.
        $isBranchesView = request()->query('view') === 'branches'
            || collect(\Modules\BladeThemeV1\Support\BranchBookConfig::typeUrlSlugs())
                ->contains(fn ($t) => request()->is($t . '/*'));
    @endphp

    <script>
        // Trang tìm kiếm có bản đồ sticky neo theo chiều cao header — nếu header tự thu gọn khi
        // cuộn (như các trang khác) thì bản đồ bị giật theo mỗi lần chiều cao đó đổi. Khoá header
        // ở trạng thái đầy đủ trong lúc ở trang này; trả lại hành vi bình thường khi rời trang.
        window.__headerAlwaysExpanded = true;
        document.addEventListener('livewire:navigating', function () {
            window.__headerAlwaysExpanded = false;
        }, { once: true });
    </script>
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <h1 class="sr-only">Tìm kiếm phòng</h1>

    <link rel="stylesheet" href="{{ asset('css/search-page.css') }}?v={{ filemtime(public_path('css/search-page.css')) }}">

    <link rel="stylesheet" href="{{ asset('css/leaflet.min.css') }}" />

    {{-- Danh sách phòng thật, chỉ hiện khi trình duyệt TẮT JavaScript — kết quả tìm kiếm bình
         thường (bên dưới) hoàn toàn do JS bơm vào DOM sau khi gọi API, nên trình duyệt/crawler tắt
         JS không bao giờ thấy được link tới phòng nào cả. <noscript> không hiển thị gì khi JS bật
         nên KHÔNG ảnh hưởng giao diện hiện tại, chỉ để lộ link thật cho crawler không chạy JS (xem
         BladeThemeV1Controller::searchProduct()). --}}
    @if($noscriptRoomLinks->isNotEmpty())
        <noscript>
            <div class="md:max-w-11xl md:mx-auto md:px-6 py-6">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Danh sách phòng</h2>
                <ul class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    @foreach($noscriptRoomLinks as $room)
                        <li>
                            <a href="{{ $room['url'] }}" class="block p-4 rounded-xl border border-gray-200">
                                <span class="block font-semibold text-gray-900">{{ $room['name'] }}</span>
                                <span class="block text-sm text-gray-500">{{ number_format($room['price']) }}đ</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <x-bladethemev1::paginate :items="$noscriptRooms" />
            </div>
        </noscript>
    @endif

    <div id="search-layout" class="md:max-w-11xl md:mx-auto md:px-6">

        <div id="rooms-left-panel" class="{{ $isBranchesView ? 'branches-peek' : '' }}">
            <div id="sheet-handle" class="md:hidden"></div>
            <div id="sheet-scroll">
                <div id="search-results-mount">
                    <div class="mt-0 md:mt-[30px] search-count-header">
                        <p class="search-count-title" style="font-weight:500;color:#6b7280;">Đang tải kết quả...</p>
                    </div>
                    <div id="search-results-body"></div>
                </div>
                <script type="application/json" id="branch-map-data">[]</script>
            </div>
        </div>

        {{-- Luôn hiện bản đồ ở đây, kể cả ?view=branches (trước đây bị thay bằng panel đặt lịch
             inline khi chọn 1 chi nhánh — đã bỏ, card chi nhánh giờ điều hướng thẳng sang
             /branch/{slug} thay vì load inline, xem search-results.js). --}}
        <div id="map-col">
            <div id="search-map"></div>

            @if (isset($province) && $province)
                <div style="position:absolute;top:1rem;left:1rem;z-index:10;pointer-events:none;">
                    <div
                        style="background:rgba(255,255,255,.92);backdrop-filter:blur(4px);border-radius:12px;padding:7px 14px;box-shadow:0 2px 8px rgba(0,0,0,.1);display:inline-flex;align-items:center;gap:6px;">
                        <svg style="width:14px;height:14px;color:#0f766e;flex-shrink:0;" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span style="font-size:13px;font-weight:700;color:#111827;">{{ $province->name }}</span>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <script src="{{ asset('js/leaflet.min.js') }}"></script>

    <script>
        window.__searchMapConfig = {
            lat: {{ $mapLat ?? 16.0 }},
            lng: {{ $mapLng ?? 106.0 }},
            zoom: {{ $mapZoom ?? 6 }},
            @if (isset($province) && $province && $province->lat && $province->lng)
                provinceLat: {{ $province->lat }},
                provinceLng: {{ $province->lng }},
            @else
                provinceLat: null,
                provinceLng: null,
            @endif
        };
    </script>
    <script src="{{ asset('js/search-map.js') }}?v={{ filemtime(public_path('js/search-map.js')) }}"></script>

    <script src="{{ asset('js/home-sections.min.js') }}?v={{ filemtime(public_path('js/home-sections.min.js')) }}"></script>
    <script src="{{ asset('js/search-results.min.js') }}?v={{ filemtime(public_path('js/search-results.min.js')) }}"></script>
 @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
