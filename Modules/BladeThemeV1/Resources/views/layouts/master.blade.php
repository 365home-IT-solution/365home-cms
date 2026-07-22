@php
    $generalSettings = new \App\Settings\GeneralSettings();

    $metaTags = [
        'canonical' => $generalSettings->canonical,
        'robots' => $generalSettings->robots,
        'og_type' => $generalSettings->og_type,
        'og_url' => $generalSettings->og_url,
        'og_title' => $generalSettings->og_title,
        'og_description' => $generalSettings->og_description,
        'og_image' => $generalSettings->og_image,
        'og_locale' => $generalSettings->og_locale,
        'twitter_card' => $generalSettings->twitter_card,
        'twitter_url' => $generalSettings->twitter_url,
        'twitter_title' => $generalSettings->twitter_title,
        'twitter_description' => $generalSettings->twitter_description,
        'twitter_image' => $generalSettings->twitter_image,
        'twitter_site' => $generalSettings->twitter_site,
        'twitter_creator' => $generalSettings->twitter_creator,
        'author' => $generalSettings->author,
        'article_published_time' => $generalSettings->article_published_time,
        'article_modified_time' => $generalSettings->article_modified_time,
    ];

    $favicon = $generalSettings->site_favicon ?? 'favicons/favicon.png';
@endphp

<!DOCTYPE html>
<html lang="{{ env('APP_LOCALE', 'vi') }}">

<head>
    <!-- Basic Meta Tags -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title')</title>

    <!-- SEO Meta Tags -->
    @php
        $robotsBase = $metaTags['robots'] ?? 'index, follow';
        // Thêm max-snippet và max-image-preview nếu chưa có, giúp Google hiển thị rich snippet đẹp hơn
        $robotsFull = str_contains($robotsBase, 'noindex')
            ? $robotsBase
            : $robotsBase . ', max-snippet:-1, max-image-preview:large, max-video-preview:-1';
    @endphp
    <meta name="robots" content="{{ $robotsFull }}">

    <!-- Page-specific meta (canonical, og:, twitter:, JSON-LD) are injected per page via seo component -->
    @yield('meta')

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">

    <!-- Styles -->
    @vite(['Resources/assets/sass/app.scss', 'Resources/assets/js/app.js'], 'build-bladethemev1')
    {{-- Real-time "khung giờ đang bị admin giữ chỗ" (xem App\Services\TimeslotHoldService) — nhúng
         Ở LAYOUT DÙNG CHUNG (không riêng product-detail) để hoạt động trên MỌI trang có bảng chọn
         khung giờ (trang chủ, trang chi nhánh, trang chi tiết phòng...), dùng build Vite CHÍNH
         (public/build), khác với build-bladethemev1 ở dòng trên — 2 pipeline độc lập, không xung đột. --}}
    @vite(['resources/js/echo-client.js'])
    <link rel="shortcut icon" href="{{ asset('/storage/' . $favicon) }}" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --color-primary: {{ $primaryColor }};
            --color-primary-rgb: {{ $primaryColorRgb }};
            --color-primary-light: {{ $lightPrimaryColor }};
        }
        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            font-family: 'Inter', sans-serif;
        }

        /* ========== SMOOTH SCROLL FOR ENTIRE WEBSITE ========== */
        html {
            scroll-behavior: smooth;
        }

        /* Tắt scroll-anchoring toàn site: khi header co/giãn chiều cao (sticky search bar),
           trình duyệt tự "neo" vào 1 phần tử bên dưới và tự điều chỉnh scrollY để giữ nó
           đứng yên trên màn hình -> gây rung/nhấp nháy header liên tục khi cuộn. */
        * {
            overflow-anchor: none;
        }

        /* Custom Scrollbar cho toàn website */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-light));
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, var(--color-primary-light), var(--color-primary));
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        /* Firefox scrollbar */
        * {
            scrollbar-width: thin;
            scrollbar-color: var(--color-primary) #f1f1f1;
        }

        /* Ẩn hẳn thanh cuộn (kể cả thanh cuộn trang tuỳ chỉnh ở trên) ở kích thước mobile — vẫn
           cuộn được bình thường, chỉ không hiện thanh cuộn để trông gọn như app di động. Desktop
           giữ nguyên thanh cuộn màu theme như cũ. */
        @media (max-width: 767.98px) {
            ::-webkit-scrollbar {
                display: none;
                width: 0;
                height: 0;
            }
            * {
                scrollbar-width: none;
            }
        }

        /* Smooth scroll animation cho tất cả elements có overflow */
        .overflow-auto,
        .overflow-y-auto,
        .overflow-x-auto,
        .scroll-smooth {
            scroll-behavior: smooth;
        }

        .owl-cards>* {
            padding-left: 0;
            padding-right: 0;
        }

    </style>
    <meta name="google-site-verification" content="0ZBswrf5iWy88w6bO01M5Ug3fzaHQYSVopJfACzmioc" />
    <meta name="google-site-verification" content="JxaNDMFwsnjNqpiMuX2dNb9xgCObK0fzixMaom0QD4I" />
    <link rel="stylesheet" href="{{ route('theme.css') }}">
    @livewireStyles
</head>

<body class="relative">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    @yield('content')

    @livewire('bladethemev1::bottom-sidebar')
    @livewire('bladethemev1::popup')
    @livewire('bladethemev1::auth-modal')
    @stack('scripts')
    @livewireScripts
</body>

</html>
