@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')
    @livewire('bladethemev1::breadcrumb', ['slug' => $slug, 'name' => $name])
    @livewire('bladethemev1::category-detail', ['slug' => $slug])
    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
        });
    </script>
@endsection
