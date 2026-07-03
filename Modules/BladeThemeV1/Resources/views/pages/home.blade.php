@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@section('content')

    <!-- @livewire('bladethemev1::header') -->
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

@endsection

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
    });
</script>
