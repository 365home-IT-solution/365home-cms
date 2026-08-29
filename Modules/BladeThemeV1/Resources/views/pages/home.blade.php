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
    @livewire('bladethemev1::flash-sale')

    {{-- Voucher & Ưu đãi --}}
    @livewire('bladethemev1::voucher')

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
        });
    </script>
@endsection
