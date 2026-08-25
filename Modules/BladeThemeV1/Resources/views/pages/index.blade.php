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
        <link rel="preload" as="image" href="{{ asset('images/banner-guest-mobile.webp') }}" fetchpriority="high">
    @endpush
@endif

@section('content')

    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    @if (request()->path() === '/')
        {{-- Trang chủ: chỉ render hero-section + các section tùy chỉnh --}}
        @livewire('bladethemev1::hero-section')
        @livewire('bladethemev1::flash-sale')
        @livewire('bladethemev1::voucher')

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
@endsection

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
    });
</script>
