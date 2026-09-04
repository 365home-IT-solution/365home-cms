@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@php
    $postBreadcrumbParents = [['title' => 'Bài viết', 'url' => url('/bai-viet')]];
    if (!empty($postCategory)) {
        $postBreadcrumbParents[] = [
            'title' => $postCategory->name,
            'url'   => url('/bai-viet') . '?danh-muc=' . urlencode($postCategory->name),
        ];
    }
@endphp

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')
    @livewire('bladethemev1::breadcrumb', ['slug' => $slug, 'name' => $name, 'parents' => $postBreadcrumbParents])
    @livewire('bladethemev1::post-detail', ['slug' => $slug])
    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
        });
    </script>
@endsection
