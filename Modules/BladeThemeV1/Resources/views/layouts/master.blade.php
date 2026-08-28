@php
    $generalSettings = new \App\Settings\GeneralSettings();
    $siteTheme = $generalSettings->site_theme ?? [];
    $isHomePage = request()->path() === '/';

    $metaTags = [
        'canonical' => $generalSettings->canonical,
        // Per-page override (e.g. account page passes seoData.robots = 'noindex, follow' so a
        // private, user-specific page doesn't get indexed) — falls back to the global setting.
        'robots' => (isset($seoData) && !empty($seoData['robots'])) ? $seoData['robots'] : $generalSettings->robots,
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
<html lang="{{ config('app.locale', 'vi') }}">

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

    {{-- Page-specific resource hints (e.g. LCP image preload) — pushed from individual page views,
         since a hint that's only relevant on the home page shouldn't ship on every page. --}}
    @stack('head')

    <!-- Google Fonts (non-blocking: load stylesheet without pausing rendering, apply once ready) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet"></noscript>

    {{-- jQuery loaded with `defer`, so it no longer blocks parsing — but MUST come before the
         @vite(...) tags below: those compile to type="module" scripts, which the browser also
         defers automatically, and defer/module scripts execute in DOCUMENT ORDER. app.js uses
         jQuery plugins ($.fn...) at load time, so if it executes before jQuery is defined, it
         throws "Cannot read properties of undefined (reading 'fn')". Keeping jQuery's tag first
         here (still deferred, still non-blocking) preserves the same execution order as before,
         just without pausing HTML parsing. --}}
    @unless ($isHomePage)
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
    @endunless

    <!-- Styles -->
    @if ($isHomePage)
        {{-- Reserve the measured LCP section before its component-local styles are encountered
             later in the large HTML document. This prevents the 0.413 mobile layout shift while
             keeping the exact same 150px/190px responsive design. --}}
        <style data-home-layout-critical>
            .home-explore-section{padding-top:1.5rem;padding-bottom:1.5rem;background:#fff}
            .home-explore-container{width:100%;max-width:80rem;margin-left:auto;margin-right:auto;padding-left:1rem;padding-right:1rem}
            .home-explore-title{margin:0 0 1rem;font-size:1.5rem;line-height:2rem;font-weight:700;color:#111827}
            .home-explore-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:1rem}
            .explore-card{position:relative;overflow:hidden;width:100%;height:150px;border-radius:16px;background:#f3f4f6;display:flex;align-items:center}
            .explore-card-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center}
            .explore-card-bg-right{object-position:right center}
            .explore-card-content{position:relative;z-index:1;max-width:62%;padding:0 20px;display:flex;flex-direction:column;gap:6px}
            .explore-card-badge{height:26px;width:auto;aspect-ratio:149/48;display:block}
            @media(min-width:640px){.home-explore-container{padding-left:1.5rem;padding-right:1.5rem}}
            @media(min-width:1024px){.home-explore-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.explore-card{height:190px}.explore-card-content{padding:0 28px}.explore-card-badge{height:42px}}
        </style>
        @vite(['Resources/assets/sass/home.scss', 'Resources/assets/js/home.js'], 'build-bladethemev1')
    @else
        @vite(['Resources/assets/sass/app.scss', 'Resources/assets/js/app.js'], 'build-bladethemev1')
    @endif
    {{-- Real-time "khung giờ đang bị admin giữ chỗ" (xem App\Services\TimeslotHoldService) — nhúng
         Ở LAYOUT DÙNG CHUNG (không riêng product-detail) để hoạt động trên MỌI trang có bảng chọn
         khung giờ (trang chủ, trang chi nhánh, trang chi tiết phòng...), dùng build Vite CHÍNH
         (public/build), khác với build-bladethemev1 ở dòng trên — 2 pipeline độc lập, không xung đột. --}}
    @unless ($isHomePage)
        @vite(['resources/js/echo-client.js'])
    @endunless
    {{-- Real-time "khung giờ vừa đổi giá/khuyến mãi" (xem App\Services\SlotRealtimeService) — dùng
         Node WS service riêng (websocket/server.js), KHÁC kênh Reverb ở echo-client.js phía trên.
         window.__WS_PUBLIC_URL để trống (route "services.websocket.public_url" chưa cấu hình) thì
         ws-client.js tự bỏ qua, không lỗi gì. --}}
    <script>window.__WS_PUBLIC_URL = @js(config('services.websocket.public_url'));</script>
    @unless ($isHomePage)
        @vite(['resources/js/ws-client.js'])
    @endunless
    <link rel="shortcut icon" href="{{ asset('/storage/' . $favicon) }}" type="image/x-icon">
    <style>
        :root {
            --color-primary: {{ $primaryColor }};
            --color-primary-rgb: {{ $primaryColorRgb }};
            --color-primary-light: {{ $lightPrimaryColor }};
            --color-text-secondary: {{ data_get($siteTheme, 'Secondary', '#6b7280') }};
            --color-secondary: {{ data_get($siteTheme, 'secondary', '#6b7280') }};
            --color-gray: {{ data_get($siteTheme, 'gray', '#6b7280') }};
            --color-success: {{ data_get($siteTheme, 'success', '#22c55e') }};
            --color-danger: {{ data_get($siteTheme, 'danger', '#ef4444') }};
            --color-info: {{ data_get($siteTheme, 'info', '#3b82f6') }};
            --color-warning: {{ data_get($siteTheme, 'warning', '#f59e0b') }};
            --color-background: {{ data_get($siteTheme, 'background', '#ffffff') }};
            --color-bgDark: {{ data_get($siteTheme, 'bg_dark', '#111827') }};
            --color-textDark: {{ data_get($siteTheme, 'text_dark', '#111827') }};
            --color-red9C: {{ data_get($siteTheme, 'red_9c', '#9c0000') }};
            --color-borderGray: {{ data_get($siteTheme, 'border_gray', '#e5e7eb') }};
            --color-tickGreen: {{ data_get($siteTheme, 'tick_green', '#22c55e') }};
            --color-tickYellow: {{ data_get($siteTheme, 'tick_yellow', '#eab308') }};
            --color-tickGray: {{ data_get($siteTheme, 'tick_gray', '#9ca3af') }};
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
    @livewireStyles
</head>

<body class="relative">
    @yield('content')

    @livewire('bladethemev1::bottom-sidebar')
    @livewire('bladethemev1::popup')
    @livewire('bladethemev1::auth-modal')
    @stack('scripts')
    @livewireScripts
    @if ($isHomePage)
        <script type="module">
            let homeRealtimeLoaded = false;
            const loadHomeRealtime = () => {
                if (homeRealtimeLoaded) return;
                homeRealtimeLoaded = true;
                import(@js(Vite::asset('resources/js/echo-client.js')));
                import(@js(Vite::asset('resources/js/ws-client.js')));
            };
            const boundary = document.querySelector('[data-home-realtime-boundary]');
            if (boundary && 'IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    if (!entries.some(entry => entry.isIntersecting)) return;
                    observer.disconnect();
                    loadHomeRealtime();
                });
                observer.observe(boundary);
            } else if (boundary) {
                boundary.addEventListener('pointerdown', loadHomeRealtime, { once: true, passive: true });
            }
        </script>
    @endif
</body>

</html>
