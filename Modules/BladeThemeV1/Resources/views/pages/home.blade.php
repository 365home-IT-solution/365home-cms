@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@section('content')

    <h1 class="sr-only">{{ config('app.name', '365 HOME') }} - Đặt phòng nghỉ, coworking, phòng theo giờ</h1>

    {{-- @livewire('bladethemev1::header') --}}
    @livewire('bladethemev1::drawer-menu')

    @livewire('bladethemev1::location-modal')

    {{-- Hero Section --}}
    @livewire('bladethemev1::hero-section')

    {{-- Flash Sale --}}
    <div wire:key="flash-sale-section">
        @livewire('bladethemev1::flash-sale', [], key('flash-sale'))
    </div>

    {{-- Voucher & Ưu đãi --}}
    <div wire:key="voucher-section">
        @livewire('bladethemev1::voucher', [], key('voucher'))
    </div>

    <div wire:key="footer-section">
        @livewire('bladethemev1::footer')
    </div>
    <div wire:key="contact-section">
        @livewire('bladethemev1::contact-link')
    </div>
    <div wire:key="notification-section">
        @livewire('bladethemev1::notification')
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
        });
    </script>
@endsection
