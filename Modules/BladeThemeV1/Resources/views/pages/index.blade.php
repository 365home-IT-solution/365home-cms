@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@if (request()->path() === '/')
    @push('head')
        {{-- LCP element on the home page is the "Lần đầu khám phá" banner image (background-image,
             set via inline style in flash-sale.blade.php) — the browser's preload scanner can't
             discover a CSS background-image until it parses that CSS/layout, which Lighthouse flags
             as "resource load delay". Preloading it here lets the browser fetch it immediately, in
             parallel with everything else, cutting straight into that delay without changing how
             the section renders. --}}
        @php
            $criticalBannerImage = data_get($criticalHome ?? [], 'banner.items.0.thumbnail.wide')
                ?? data_get($criticalHome ?? [], 'banner.items.0.image_url');
            $criticalBannerSrcset = collect([
                data_get($criticalHome ?? [], 'banner.items.0.thumbnail.card')
                    ? data_get($criticalHome, 'banner.items.0.thumbnail.card').' 480w' : null,
                data_get($criticalHome ?? [], 'banner.items.0.thumbnail.medium')
                    ? data_get($criticalHome, 'banner.items.0.thumbnail.medium').' 768w' : null,
                data_get($criticalHome ?? [], 'banner.items.0.thumbnail.wide')
                    ? data_get($criticalHome, 'banner.items.0.thumbnail.wide').' 1080w' : null,
            ])->filter()->implode(', ');
        @endphp
        @if ($criticalBannerImage)
            {{-- Banner tạm ẩn trên mobile (flash-sale.blade.php) — chỉ preload ở desktop. --}}
            <link rel="preload" as="image" media="(min-width: 1024px)" href="{{ $criticalBannerImage }}"
                  @if($criticalBannerSrcset) imagesrcset="{{ $criticalBannerSrcset }}" @endif
                  imagesizes="(max-width: 1023px) 100vw, 768px" fetchpriority="high">
        @endif
        {{-- Banner đầu tiên của "Ưu đãi dành cho bạn" (flash-sale.blade.php) — ảnh lớn đầu trang, khả năng
             cao là LCP; preload từ <head> để trình duyệt tải sớm. href PHẢI khớp src của <img> đó. --}}
        <link rel="preload" as="image" fetchpriority="high"
              href="{{ asset('images/offers/offer-register-640.webp') }}">
    @endpush
@endif

@section('content')

    {{-- Trang chủ: $page->title chỉ là "Trang chủ" (cũng là nhãn menu) — H1 như vậy không cho Google
         biết trang nói về gì, nên trang chủ dùng H1 mô tả riêng chứa các từ khoá chính. --}}
    @if (request()->path() === '/')
        <h1 class="sr-only">365Home - Homestay Cần Thơ tự check-in, giá rẻ, đặt phòng theo giờ, qua đêm, theo ngày</h1>
    @else
        <h1 class="sr-only">{{ $page->title ?? config('app.name', '365 HOME') }}</h1>
    @endif

    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    @if (request()->path() === '/')
        {{-- Trang chủ: chỉ render hero-section + các section tùy chỉnh. Instance mặc định này chỉ
             có thanh tìm kiếm thu gọn + menu của MOBILE (lg:hidden) — máy tính không in (xem
             App\Support\ViewportMode). --}}
        @if (\App\Support\ViewportMode::rendersMobile())
            @livewire('bladethemev1::hero-section')
            {{-- Mobile: ô tìm kiếm kiểu Agoda ngay đầu trang (thay thanh tìm kiếm gọn trong header —
                 header-main.blade.php không in thanh đó ở trang chủ nữa). --}}
            <x-bladethemev1::home.mobile-search :room-types="data_get($criticalHome ?? [], 'room_types', [])" />
        @endif
        @livewire('bladethemev1::flash-sale', ['criticalHome' => $criticalHome ?? []])
        @livewire('bladethemev1::voucher')

        {{-- Bài viết mới + chi nhánh/khu vực: link nội bộ render sẵn trong HTML cho SEO --}}
        <x-bladethemev1::home.internal-links />

    @else
        {{-- Các trang khác: render CMS components bình thường --}}
        <div>
            @foreach ($configuration as $config)
                <x-bladethemev1::layout :page="$page" :componentName="$config['component']['name']" :loop="$loop" :config="$config['layout']" :primaryColor="$primaryColor">
                    @isset($config['component'])
                        @livewire('bladethemev1::' . $config['component']['name'], ['config' => $config, 'loop' => $loop])
                    @endisset
                </x-bladethemev1::layout>
            @endforeach
        </div>
    @endif

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
        });
    </script>
@endsection
